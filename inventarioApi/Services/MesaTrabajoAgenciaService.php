<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoBodega;
use App\inventarioApi\Helpers\RolCompraHelper;
use App\inventarioApi\Repositories\CompraRepository;
use Exception;

/**
 * MESA DE TRABAJO — Administrador de Bodegas
 *
 * Vista consolidada por LÍNEA (no por compra), de todo lo que quedó
 * Aprobado con destino a una bodega de AGENCIA — sin importar si nació de
 * una Solicitud de Agencia, un pedido Trimestral, o una Extraordinaria
 * tipo Agencia. El origen es transparente para esta pantalla.
 *
 * Cuatro acciones independientes por línea/compra:
 *   - ajustarCantidad()      → solo a la baja (regla ya vigente para
 *     bodegas de Agencia en CompraService::ajustarCantidadLinea, sin cambios)
 *   - cambiarBodegaDestino() → únicamente entre bodegas de Agencia
 *   - registrarPrecios()     → en lote; deja la compra en "Comprado" en
 *     cuanto TODA su compra tiene precio — es un estado de espera real,
 *     NO envía sola.
 *   - enviarCompra()         → acción explícita del Administrador, solo
 *     disponible cuando la compra ya está en "Comprado"; genera las altas.
 *
 * La mesa de trabajo del Encargado de Área es un servicio aparte (mismo
 * espíritu, pero acotada a la bodega propia y con sus propias reglas de
 * alza) — no vive aquí.
 */
final class MesaTrabajoAgenciaService
{
    public function __construct(
        private CompraRepository $repo,
        private CompraService $compraService,
    ) {
    }

    // =====================================================================
    // Listado
    // =====================================================================

    /**
     * @param array{
     *   id_tipo_producto?: ?int, id_bodega_destino?: ?int, id_tipo_origen?: ?int,
     *   comprado?: ?bool, busqueda?: ?string
     * } $filtros
     * @param array<int> $estados Por defecto [Aprobada, Comprada]. Se puede
     *   pasar [Enviada] para el filtro "Enviadas" (para pedirle a la agencia
     *   que reciba) o la descarga de Excel con ese filtro.
     */
    public function listar(?int $idPuestoSesion, array $filtros, array $estados, int $pagina, int $porPagina): array
    {
        $this->exigirAdministrador($idPuestoSesion);

        return $this->repo->listarLineasMesaTrabajo(TipoBodega::AGENCIA->value, $filtros, $estados, $pagina, $porPagina);
    }

    /** Bodegas de agencia activas — para el selector de "cambiar destino". */
    public function listarBodegasDestinoDisponibles(?int $idPuestoSesion): array
    {
        $this->exigirAdministrador($idPuestoSesion);

        return $this->repo->listarBodegasPorTipo(TipoBodega::AGENCIA->value);
    }

    // =====================================================================
    // Ajustar cantidad (solo a la baja — ya lo valida CompraService)
    // =====================================================================

    public function ajustarCantidad(?int $idPuestoSesion, int $idCompra, int $idLinea, float $cantidad): void
    {
        $this->exigirAdministrador($idPuestoSesion);
        $this->compraService->ajustarCantidadLinea($idCompra, $idLinea, $cantidad);
    }

    // =====================================================================
    // Autoguardado de precio (borrador) — se llama en cada blur del input,
    // no espera a "Registrar". No marca comprado_con_precio ni auto-envía;
    // eso lo hace registrarPrecios() más abajo, cuando el usuario confirma.
    // =====================================================================

    public function guardarPrecioBorrador(?int $idPuestoSesion, int $idCompra, int $idLinea, float $precio): void
    {
        $this->exigirAdministrador($idPuestoSesion);

        if ($precio <= 0) {
            throw new Exception('El precio debe ser mayor a cero');
        }

        $compra = $this->repo->obtenerCompraConBloqueo($idCompra);
        if (!$compra) {
            throw new Exception('La compra indicada no existe');
        }
        $estadoActual = EstadoCompra::from((int) $compra->id_estado);
        if (!in_array($estadoActual, [EstadoCompra::APROBADA, EstadoCompra::COMPRADA], true)) {
            throw new Exception("Esta compra está en estado '{$estadoActual->nombre()}' y ya no admite cambios en la mesa de trabajo");
        }

        $linea = $this->repo->obtenerLineaConBloqueo($idCompra, $idLinea);
        if (!$linea) {
            throw new Exception('La línea indicada no existe en esta compra');
        }
        if ((bool) $linea->comprado_con_precio) {
            throw new Exception('Esta línea ya fue confirmada como comprada, no admite más cambios de precio');
        }

        $this->repo->guardarPrecioBorrador($idLinea, $precio);
    }

    /**
     * Igual que guardarPrecioBorrador() pero para varias líneas de una sola
     * vez — la usa la carga del Excel reimportado. A diferencia de las
     * demás operaciones en lote, esta NO es todo-o-nada: si una línea del
     * archivo ya fue confirmada como comprada (o ya no existe), se omite
     * en vez de abortar el resto — es normal que el Excel reimportado
     * traiga filas que ya se procesaron por otro medio mientras tanto.
     *
     * @param array<array{id_compra:int,id_linea:int,precio_unitario:float}> $lineas
     * @return array{procesadas:int, omitidas:array<array{id_linea:int,motivo:string}>}
     */
    public function guardarPreciosBorradorLote(?int $idPuestoSesion, array $lineas): array
    {
        $this->exigirAdministrador($idPuestoSesion);

        if (empty($lineas)) {
            throw new Exception('No se recibió ninguna línea para procesar');
        }

        $procesadas = 0;
        $omitidas   = [];

        foreach ($lineas as $l) {
            $idCompra = (int) ($l['id_compra'] ?? 0);
            $idLinea  = (int) ($l['id_linea'] ?? 0);
            $precio   = (float) ($l['precio_unitario'] ?? 0);

            if ($idCompra < 1 || $idLinea < 1 || $precio <= 0) {
                $omitidas[] = ['id_linea' => $idLinea, 'motivo' => 'Fila con datos incompletos o precio inválido'];
                continue;
            }

            try {
                $this->guardarPrecioBorrador($idPuestoSesion, $idCompra, $idLinea, $precio);
                $procesadas++;
            } catch (Exception $e) {
                $omitidas[] = ['id_linea' => $idLinea, 'motivo' => $e->getMessage()];
            }
        }

        return ['procesadas' => $procesadas, 'omitidas' => $omitidas];
    }

    // =====================================================================
    // Cambiar bodega destino — únicamente entre bodegas de Agencia
    // =====================================================================

    public function cambiarBodegaDestino(?int $idPuestoSesion, int $idCompra, int $idLinea, int $idBodegaDestinoNueva, string $idUsuarioSesion): void
    {
        $this->exigirAdministrador($idPuestoSesion);

        $compra = $this->repo->obtenerCompraConBloqueo($idCompra);
        if (!$compra) {
            throw new Exception('La compra indicada no existe');
        }
        $estadoActual = EstadoCompra::from((int) $compra->id_estado);
        if (!in_array($estadoActual, [EstadoCompra::APROBADA, EstadoCompra::COMPRADA], true)) {
            throw new Exception("Esta compra está en estado '{$estadoActual->nombre()}' y ya no admite cambios en la mesa de trabajo");
        }

        $linea = $this->repo->obtenerLineaConBloqueo($idCompra, $idLinea);
        if (!$linea) {
            throw new Exception('La línea indicada no existe en esta compra');
        }
        if ((bool) $linea->comprado_con_precio) {
            throw new Exception('No se puede cambiar la bodega destino de una línea que ya fue comprada');
        }

        $bodegaNueva = $this->repo->obtenerBodegaActiva($idBodegaDestinoNueva);
        if (!$bodegaNueva) {
            throw new Exception('La bodega destino seleccionada no existe o se encuentra inactiva');
        }
        if (TipoBodega::from((int) $bodegaNueva->id_tipo) !== TipoBodega::AGENCIA) {
            throw new Exception('En esta mesa de trabajo solo se puede redirigir entre bodegas de agencia');
        }

        $idBodegaAnterior = (int) $linea->id_bodega_destino;

        if ($idBodegaAnterior === $idBodegaDestinoNueva) {
            return; // sin cambio real, no genera historial vacío
        }

        $this->repo->actualizarBodegaDestinoLinea($idLinea, $idBodegaDestinoNueva);
        $this->repo->registrarHistorialDestino($idLinea, $idBodegaAnterior, $idBodegaDestinoNueva, $idUsuarioSesion);
    }

    /**
     * Igual que cambiarBodegaDestino() pero para varias líneas de una sola vez
     * (pueden pertenecer a compras distintas). Todo o nada: si una línea no
     * es válida, no se aplica ningún cambio del lote.
     *
     * @param array<array{id_compra:int,id_linea:int}> $lineas
     * @return array{procesadas:int}
     */
    public function cambiarBodegaDestinoLote(?int $idPuestoSesion, array $lineas, int $idBodegaDestinoNueva, string $idUsuarioSesion): array
    {
        $this->exigirAdministrador($idPuestoSesion);

        if (empty($lineas)) {
            throw new Exception('No se recibió ninguna línea para procesar');
        }

        $procesadas = 0;
        foreach ($lineas as $i => $l) {
            $idCompra = (int) ($l['id_compra'] ?? 0);
            $idLinea  = (int) ($l['id_linea'] ?? 0);

            if ($idCompra < 1 || $idLinea < 1) {
                throw new Exception('Error de consistencia: la línea #' . ($i + 1) . ' tiene datos inválidos');
            }

            $this->cambiarBodegaDestino($idPuestoSesion, $idCompra, $idLinea, $idBodegaDestinoNueva, $idUsuarioSesion);
            $procesadas++;
        }

        return ['procesadas' => $procesadas];
    }

    // =====================================================================
    // Enviar — acción explícita, una vez que la compra está en "Comprado"
    // (todas sus líneas con precio). Genera las altas físicas.
    // =====================================================================

    /** @return array{ids_altas: array<int>} */
    public function enviarCompra(?int $idPuestoSesion, int $idCompra, string $idUsuarioSesion): array
    {
        $this->exigirAdministrador($idPuestoSesion);

        $idsAltas = $this->compraService->enviar($idCompra, $idUsuarioSesion);

        return ['ids_altas' => $idsAltas];
    }

    // =====================================================================
    // Registrar precios — en lote (independiente por línea; cada una puede
    // pertenecer a una compra distinta)
    // =====================================================================

    /**
     * @param array<array{id_compra:int,id_linea:int,precio_unitario:float}> $lineas
     * @return array{procesadas:int, ids_altas:array<int>}
     */
    public function registrarPrecios(?int $idPuestoSesion, array $lineas, string $idUsuarioSesion): array
    {
        $this->exigirAdministrador($idPuestoSesion);

        if (empty($lineas)) {
            throw new Exception('No se recibió ninguna línea para procesar');
        }

        $procesadas = 0;
        $idsAltas   = [];

        foreach ($lineas as $i => $l) {
            $idCompra = (int) ($l['id_compra'] ?? 0);
            $idLinea  = (int) ($l['id_linea'] ?? 0);
            $precio   = (float) ($l['precio_unitario'] ?? 0);

            if ($idCompra < 1 || $idLinea < 1 || $precio <= 0) {
                throw new Exception('Error de consistencia: la línea #' . ($i + 1) . ' tiene datos inválidos (falta id_compra, id_linea o el precio no es mayor a cero)');
            }

            $resultadoEnvio = $this->compraService->marcarLineaComprada($idCompra, $idLinea, $precio, $idUsuarioSesion);
            $procesadas++;

            if ($resultadoEnvio !== null) {
                $idsAltas = array_merge($idsAltas, $resultadoEnvio);
            }
        }

        return ['procesadas' => $procesadas, 'ids_altas' => $idsAltas];
    }

    // -----------------------------------------------------------------

    private function exigirAdministrador(?int $idPuestoSesion): void
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede operar la mesa de trabajo');
        }
    }
}