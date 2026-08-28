<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoBodega;
use App\inventarioApi\Enums\TipoOrigenCompra;
use App\inventarioApi\Helpers\BodegaHelper;
use App\inventarioApi\Helpers\CierreHelper;
use App\inventarioApi\Helpers\MovimientoHelper;
use App\inventarioApi\Helpers\StockHelper;
use App\inventarioApi\Repositories\CompraRepository;
use Exception;
use PDO;

/**
 * ELIMINACIÓN / REVERSA — carga masiva.
 *
 * Solo aplica a compras cuyo origen sea "Carga Masiva" — no es un
 * eliminador general de compras. Dos reglas, ambas obligatorias:
 *   - Mismo día en que se cargó (created_at es hoy).
 *   - No anterior al último cierre contable (CierreHelper).
 *
 * Y una regla de seguridad que no estaba explícita en el pedido pero es
 * indispensable: si la compra ya llegó a Registrada y parte de esa
 * existencia YA SE CONSUMIÓ (una entrega ya tomó stock de ese lote, o ya
 * se emitieron correlativos de ese rango), NO se puede eliminar esa línea
 * — no se puede hacer como que algo que ya salió de la bodega nunca
 * existió. Se bloquea con un mensaje claro en vez de dejar el stock en
 * negativo o inconsistente.
 *
 * Reutiliza los Helpers que ya existían para exactamente este propósito:
 * StockHelper::revertirPorEliminacionLote() y
 * MovimientoHelper::registrarReversaAlta() (movimiento tipo 11, "Baja por
 * reversa de alta") — no reinventa nada de eso.
 */
final class CargaMasivaEliminacionService
{
    public function __construct(
        private PDO $connect,
        private CompraRepository $repo,
        private StockHelper $stockHelper,
        private MovimientoHelper $movimientoHelper,
        private CierreHelper $cierreHelper,
        private BodegaHelper $bodegaHelper,
    ) {
    }

    // =====================================================================
    // Listado — para que el usuario elija qué eliminar
    // =====================================================================

    public function listarCargasDeHoy(string $tipo): array
    {
        return $this->repo->listarCargasMasivasDeHoy($tipo);
    }

    public function listarComprasDeCarga(int $idCargaMasiva): array
    {
        return $this->repo->listarComprasDeCarga($idCargaMasiva);
    }

    // =====================================================================
    // Eliminar UNA compra
    // =====================================================================

    public function eliminarCompra(?int $idPuestoSesion, int $idCompra, string $idUsuarioSesion): void
    {
        $this->connect->beginTransaction();
        try {
            $compra = $this->repo->obtenerCompraParaEliminar($idCompra);
            if (!$compra) {
                throw new Exception('La compra indicada no existe');
            }

            $this->validarEliminable($compra, $idPuestoSesion);
            $this->ejecutarEliminacion($compra, $idUsuarioSesion);

            $this->connect->commit();
        } catch (Exception $e) {
            if ($this->connect->inTransaction()) {
                $this->connect->rollBack();
            }
            throw $e;
        }
    }

    // =====================================================================
    // Eliminar TODA una carga — tolerante a fallos individuales
    // =====================================================================

    /** @return array{eliminadas:int, omitidas:array<array{id_compra:int,motivo:string}>} */
    public function eliminarCarga(?int $idPuestoSesion, int $idCargaMasiva, string $idUsuarioSesion): array
    {
        $carga = $this->repo->obtenerCargaMasiva($idCargaMasiva);
        if (!$carga) {
            throw new Exception('La carga masiva indicada no existe');
        }

        $compras = $this->repo->listarComprasDeCarga($idCargaMasiva);
        if (empty($compras)) {
            throw new Exception('Esta carga no tiene compras asociadas (puede que ya se hayan eliminado)');
        }

        $eliminadas = 0;
        $omitidas   = [];

        foreach ($compras as $c) {
            $this->connect->beginTransaction();
            try {
                $compra = $this->repo->obtenerCompraParaEliminar((int) $c->id);
                if (!$compra) {
                    throw new Exception('Ya no existe');
                }
                $this->validarEliminable($compra, $idPuestoSesion);
                $this->ejecutarEliminacion($compra, $idUsuarioSesion);
                $this->connect->commit();
                $eliminadas++;
            } catch (Exception $e) {
                if ($this->connect->inTransaction()) {
                    $this->connect->rollBack();
                }
                $omitidas[] = ['id_compra' => (int) $c->id, 'motivo' => $e->getMessage()];
            }
        }

        return ['eliminadas' => $eliminadas, 'omitidas' => $omitidas];
    }

    // =====================================================================
    // Validación
    // =====================================================================

    private function validarEliminable(object $compra, ?int $idPuestoSesion): void
    {
        if (TipoOrigenCompra::from((int) $compra->id_tipo_origen) !== TipoOrigenCompra::CARGA_MASIVA) {
            throw new Exception('Solo se pueden eliminar compras que nacieron de una carga masiva');
        }

        $fechaCompra = date('Y-m-d', strtotime((string) $compra->created_at));
        if ($fechaCompra !== date('Y-m-d')) {
            throw new Exception('Solo se puede eliminar el mismo día en que se cargó');
        }

        if (!$this->cierreHelper->esPosteriorAlUltimoCierre((string) $compra->created_at)) {
            throw new Exception('No se puede eliminar: es anterior al último cierre contable');
        }

        if (TipoBodega::from((int) $compra->id_tipo_bodega) === TipoBodega::AGENCIA) {
            if (!\App\inventarioApi\Helpers\RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
                throw new Exception('Solo el Administrador de Bodegas puede eliminar cargas de bodegas de agencia');
            }
        } else {
            $idBodegaPropia = $this->bodegaHelper->obtenerBodegaDelEncargado();
            if ($idBodegaPropia === null || $idBodegaPropia !== (int) $compra->id_bodega) {
                throw new Exception('Solo puede eliminar cargas de su propia bodega de área');
            }
        }
    }

    // =====================================================================
    // Ejecución de la reversa — según hasta dónde haya llegado la compra
    // =====================================================================

    private function ejecutarEliminacion(object $compra, string $idUsuarioSesion): void
    {
        $estado = EstadoCompra::from((int) $compra->id_estado);

        if ($estado === EstadoCompra::REGISTRADA) {
            $this->revertirLotesYAltas((int) $compra->id);
        } elseif ($estado === EstadoCompra::ENVIADA) {
            // Las altas existen pero siguen en Pendiente — nunca tocaron stock, solo se borran.
            $this->repo->eliminarAltasPorCompra((int) $compra->id);
        }
        // Comprada / Aprobada: nada aparte de la compra misma que revertir.

        $this->repo->eliminarLineasCompra((int) $compra->id);
        $this->repo->eliminarCompra((int) $compra->id);
    }

    /**
     * @throws Exception si alguna línea ya tiene consumo parcial — en ese
     *   caso NO se revierte nada de esta compra (todo o nada), para no
     *   dejarla a medio revertir.
     */
    private function revertirLotesYAltas(int $idCompra): void
    {
        $altas = $this->repo->obtenerAltasPorCompra($idCompra);

        // Paso 1 — validar TODOS los lotes antes de tocar nada.
        $lotes = [];
        foreach ($altas as $alta) {
            $lote = $this->repo->obtenerLotePorAlta((int) $alta->id, (int) $alta->id_tipo_producto);
            if (!$lote) {
                continue; // no debería pasar (Registrada implica lote), pero no bloquea
            }

            $this->exigirLoteSinConsumo($alta, $lote);
            $lotes[] = ['alta' => $alta, 'lote' => $lote];
        }

        // Paso 2 — ya validado todo, revertir de verdad.
        foreach ($lotes as ['alta' => $alta, 'lote' => $lote]) {
            $cantidad = $this->cantidadOriginalLote((int) $alta->id_tipo_producto, $lote);

            $this->stockHelper->revertirPorEliminacionLote(
                (int) $alta->id_bodega_destino, (int) $alta->id_producto, (int) $alta->id_unidad, $cantidad
            );
            $this->movimientoHelper->registrarReversaAlta(
                (int) $alta->id_bodega_destino, (int) $alta->id_producto, (int) $alta->id_unidad,
                $cantidad, $lote->_tabla, (int) $lote->id,
                $alta->precio_unitario !== null ? (float) $alta->precio_unitario : null,
            );
            $this->repo->eliminarLote($lote->_tabla, (int) $lote->id);
        }

        $this->repo->eliminarAltasPorCompra($idCompra);
    }

    private function exigirLoteSinConsumo(object $alta, object $lote): void
    {
        $idTipo = (int) $alta->id_tipo_producto;

        if ($idTipo === 1) { // Correlativo
            if ((int) $lote->correlativo_siguiente !== (int) $lote->correlativo_inicial) {
                throw new Exception("'{$alta->producto}': ya se emitieron correlativos de este lote — no se puede eliminar");
            }
            return;
        }

        // Expiración / Normal: cantidad_disponible debe seguir igual a lo enviado en el alta.
        if (abs((float) $lote->cantidad_disponible - (float) $alta->cantidad_enviada) > 0.0001) {
            throw new Exception("'{$alta->producto}': ya se entregó parte de esta existencia — no se puede eliminar");
        }
    }

    private function cantidadOriginalLote(int $idTipoProducto, object $lote): float
    {
        if ($idTipoProducto === 1) {
            return (float) ((int) $lote->correlativo_final - (int) $lote->correlativo_inicial + 1);
        }

        return (float) $lote->cantidad_disponible;
    }
}