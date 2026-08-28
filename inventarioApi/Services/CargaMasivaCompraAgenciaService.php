<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoBodega;
use App\inventarioApi\Enums\TipoOrigenCompra;
use App\inventarioApi\Helpers\RolCompraHelper;
use App\inventarioApi\Repositories\CompraRepository;
use Exception;
use PDO;

/**
 * CARGA MASIVA — Administrador de Bodegas, compras de Agencia.
 *
 * Inserta compras directo en el punto del ciclo que corresponda —
 * Aprobada, Comprada, Enviada o Registrada — sin pasar manualmente por
 * los pasos intermedios. No hay lógica de estado nueva: cada grupo se
 * hace pasar literalmente por los mismos métodos que ya usa el resto del
 * sistema (CompraService::crear/marcarLineaComprada/enviar/registrar), en
 * la misma secuencia real, así que el resultado es indistinguible de una
 * compra hecha a mano paso a paso — mismo stock, mismos movimientos,
 * mismas altas.
 *
 * Cada fila del Excel es una LÍNEA; varias líneas se agrupan en una sola
 * compra mediante una "referencia" que el usuario asigna libremente (ej.
 * un número de factura) — todas las filas con la misma referencia deben
 * compartir bodega y estado destino.
 */
final class CargaMasivaCompraAgenciaService
{
    /** Orden de "profundidad" de cada estado destino — cuántos pasos hay que ejecutar. */
    private const ORDEN_ESTADOS = [
        'aprobada'  => EstadoCompra::APROBADA,
        'comprada'  => EstadoCompra::COMPRADA,
        'enviada'   => EstadoCompra::ENVIADA,
        'registrada'=> EstadoCompra::REGISTRADA,
    ];

    public function __construct(
        private PDO $connect,
        private CompraRepository $repo,
        private CompraService $compraService,
    ) {
    }

    /**
     * @param array<array{
     *   referencia: string,
     *   id_bodega: int,
     *   estado: string,
     *   lineas: array<array{
     *     id_producto:int, id_unidad:int, cantidad:float, precio_unitario?:float,
     *     fecha_expiracion?:string, correlativo_inicial?:int,
     *     serie?:?string, resolucion?:?string, fecha_resolucion?:?string
     *   }>
     * }> $grupos
     * @return array{procesados:int, ids_compras:array<int>, omitidos:array<array{referencia:string,motivo:string}>}
     *
     * IMPORTANTE: este método maneja sus PROPIAS transacciones, una por
     * grupo — el router NO debe envolver la llamada completa en una sola
     * transacción, porque si un grupo falla a medio camino (ej. la línea 3
     * de 5 tiene un precio inválido), lo que ya se alcanzó a insertar de
     * ESE grupo debe revertirse sin afectar a los demás grupos que sí
     * están bien.
     */
    public function procesarCarga(?int $idPuestoSesion, array $grupos, string $idUsuarioSesion): array
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede realizar cargas masivas de compras de agencia');
        }

        if (empty($grupos)) {
            throw new Exception('No se recibió ningún grupo de líneas para procesar');
        }

        $idsCompras = [];
        $omitidos   = [];

        foreach ($grupos as $grupo) {
            $referencia = (string) ($grupo['referencia'] ?? '(sin referencia)');

            $this->connect->beginTransaction();
            try {
                $idCompra = $this->procesarGrupo($grupo, $idUsuarioSesion);
                $this->connect->commit();
                $idsCompras[] = $idCompra;
            } catch (Exception $e) {
                if ($this->connect->inTransaction()) {
                    $this->connect->rollBack();
                }
                $omitidos[] = ['referencia' => $referencia, 'motivo' => $e->getMessage()];
            }
        }

        if (!empty($idsCompras)) {
            $idCarga = $this->repo->crearCargaMasiva('compra_agencia', $idUsuarioSesion, count($idsCompras));
            foreach ($idsCompras as $idCompra) {
                $this->repo->vincularCompraACarga($idCompra, $idCarga);
            }
        }

        return [
            'procesados'   => count($idsCompras),
            'ids_compras'  => $idsCompras,
            'omitidos'     => $omitidos,
        ];
    }

    private function procesarGrupo(array $grupo, string $idUsuarioSesion): int
    {
        $referencia = (string) ($grupo['referencia'] ?? '');
        $idBodega   = (int) ($grupo['id_bodega'] ?? 0);
        $estado     = strtolower(trim((string) ($grupo['estado'] ?? '')));
        $lineas     = $grupo['lineas'] ?? [];

        if ($referencia === '') {
            throw new Exception('Falta la referencia que agrupa las líneas de esta compra');
        }
        if (!isset(self::ORDEN_ESTADOS[$estado])) {
            throw new Exception("Estado '{$grupo['estado']}' inválido — use Aprobada, Comprada, Enviada o Registrada");
        }
        $estadoDestino = self::ORDEN_ESTADOS[$estado];

        $bodega = $this->repo->obtenerBodegaActiva($idBodega);
        if (!$bodega) {
            throw new Exception("La bodega #{$idBodega} no existe o está inactiva");
        }
        if (TipoBodega::from((int) $bodega->id_tipo) !== TipoBodega::AGENCIA) {
            throw new Exception("La bodega #{$idBodega} no es de tipo Agencia");
        }

        if (empty($lineas)) {
            throw new Exception('No trae ninguna línea de producto');
        }

        $lineasValidadas = [];
        foreach ($lineas as $i => $l) {
            $lineasValidadas[] = $this->validarLinea($l, $estadoDestino, $i + 1);
        }

        // Paso 1 — crear en Aprobada (siempre, es el punto de entrada de toda compra)
        $lineasCrear = array_map(static fn ($l) => [
            'id_producto' => $l['id_producto'], 'id_unidad' => $l['id_unidad'], 'cantidad' => $l['cantidad'],
        ], $lineasValidadas);

        $idCompra = $this->compraService->crear(
            idBodega: $idBodega,
            tipoOrigen: TipoOrigenCompra::CARGA_MASIVA,
            estadoInicial: EstadoCompra::APROBADA,
            lineas: $lineasCrear,
            idUsuarioAdmin: $idUsuarioSesion,
            requiereAutorizacion: false,
        );

        if ($estadoDestino === EstadoCompra::APROBADA) {
            return $idCompra;
        }

        // Mapa producto+unidad -> datos de la fila original, para encontrar
        // el precio/lote correcto de cada línea ya creada (sin asumir orden).
        $porProductoUnidad = [];
        foreach ($lineasValidadas as $l) {
            $porProductoUnidad[$l['id_producto'] . '-' . $l['id_unidad']] = $l;
        }

        // Paso 2 — precio -> Comprada
        $lineasCreadas = $this->repo->obtenerLineas($idCompra);
        foreach ($lineasCreadas as $lc) {
            $clave = $lc->id_producto . '-' . $lc->id_unidad;
            $precio = (float) $porProductoUnidad[$clave]['precio_unitario'];
            $this->compraService->marcarLineaComprada($idCompra, (int) $lc->id, $precio, $idUsuarioSesion);
        }

        if ($estadoDestino === EstadoCompra::COMPRADA) {
            return $idCompra;
        }

        // Paso 3 — enviar -> Enviada (genera las altas)
        $this->compraService->enviar($idCompra, $idUsuarioSesion);

        if ($estadoDestino === EstadoCompra::ENVIADA) {
            return $idCompra;
        }

        // Paso 4 — ingresar un lote por alta -> Registrada
        $altas = $this->repo->obtenerAltasPorCompra($idCompra);
        foreach ($altas as $alta) {
            $clave = $alta->id_producto . '-' . $alta->id_unidad;
            $this->crearLotePorTipo($alta, $porProductoUnidad[$clave] ?? [], $idUsuarioSesion);
        }
        $this->compraService->registrar($idCompra);

        return $idCompra;
    }

    /** @return array{id_producto:int,id_unidad:int,cantidad:float,precio_unitario?:float,...} */
    private function validarLinea(array $l, EstadoCompra $estadoDestino, int $numeroFila): array
    {
        $idProducto = (int) ($l['id_producto'] ?? 0);
        $idUnidad   = (int) ($l['id_unidad'] ?? 0);
        $cantidad   = (float) ($l['cantidad'] ?? 0);

        if ($idProducto < 1 || $idUnidad < 1 || $cantidad <= 0) {
            throw new Exception("Fila {$numeroFila}: producto, unidad y cantidad son obligatorios (cantidad mayor a cero)");
        }
        if (!$this->repo->existeComboProductoUnidadActivo($idProducto, $idUnidad)) {
            throw new Exception("Fila {$numeroFila}: la combinación de producto/unidad no es válida o está inactiva");
        }

        $requierePrecio = in_array($estadoDestino, [EstadoCompra::COMPRADA, EstadoCompra::ENVIADA, EstadoCompra::REGISTRADA], true);
        $precio = isset($l['precio_unitario']) ? (float) $l['precio_unitario'] : null;
        if ($requierePrecio && (!$precio || $precio <= 0)) {
            throw new Exception("Fila {$numeroFila}: el estado destino requiere precio unitario (mayor a cero)");
        }

        return [
            'id_producto'      => $idProducto,
            'id_unidad'        => $idUnidad,
            'cantidad'         => $cantidad,
            'precio_unitario'  => $precio,
            'fecha_expiracion' => $l['fecha_expiracion'] ?? null,
            'correlativo_inicial' => isset($l['correlativo_inicial']) ? (int) $l['correlativo_inicial'] : null,
            'serie'            => $l['serie'] ?? null,
            'resolucion'       => $l['resolucion'] ?? null,
            'fecha_resolucion' => $l['fecha_resolucion'] ?? null,
        ];
    }

    // ── Creación de lote (idéntico criterio al "dar de alta directa" de Área) ──

    private function crearLotePorTipo(object $alta, array $datosLote, string $idUsuarioSesion): void
    {
        $idBodega   = (int) $alta->id_bodega_destino;
        $idProducto = (int) $alta->id_producto;
        $idUnidad   = (int) $alta->id_unidad;
        $cantidad   = (float) $alta->cantidad_enviada;
        $precio     = $alta->precio_unitario !== null ? (float) $alta->precio_unitario : null;

        [$idLote, $tablaLote] = match ((int) $alta->id_tipo_producto) {
            1 => [$this->crearLoteCorrelativo($alta, $datosLote, $idUsuarioSesion), 'lotes_correlativo'],
            2 => [$this->crearLoteExpiracion($alta, $datosLote, $idUsuarioSesion), 'lotes_expiracion'],
            default => [$this->repo->crearLoteNormal($idBodega, $idProducto, $idUnidad, $cantidad, (int) $alta->id, $idUsuarioSesion, $precio), 'lotes_normal'],
        };

        $this->repo->incrementarStockPorAlta($idBodega, $idProducto, $idUnidad, $cantidad);
        $this->repo->registrarMovimientoAltaCompra($idBodega, $idProducto, $idUnidad, $cantidad, $tablaLote, $idLote, $idUsuarioSesion, $precio);
        $this->repo->ingresarLoteEnAlta((int) $alta->id, (float) $alta->cantidad_ingresada, $cantidad, $cantidad);
    }

    private function crearLoteExpiracion(object $alta, array $datosLote, string $idUsuarioSesion): int
    {
        $fecha = $datosLote['fecha_expiracion'] ?? null;
        if (!$fecha) {
            throw new Exception("El producto '{$alta->producto}' requiere fecha de expiración para cargar en estado Registrada");
        }

        return $this->repo->crearLoteExpiracion(
            (int) $alta->id_bodega_destino, (int) $alta->id_producto, (int) $alta->id_unidad,
            $fecha, (float) $alta->cantidad_enviada, (int) $alta->id, $idUsuarioSesion,
            $alta->precio_unitario !== null ? (float) $alta->precio_unitario : null,
        );
    }

    private function crearLoteCorrelativo(object $alta, array $datosLote, string $idUsuarioSesion): int
    {
        $correlativoInicial = $datosLote['correlativo_inicial'] ?? null;
        if (!$correlativoInicial || $correlativoInicial < 1) {
            throw new Exception("El producto '{$alta->producto}' requiere correlativo inicial para cargar en estado Registrada");
        }

        $cantidad = (int) round((float) $alta->cantidad_enviada);
        if (abs($cantidad - (float) $alta->cantidad_enviada) > 0.0001) {
            throw new Exception("El producto '{$alta->producto}' es de control Correlativo — la cantidad debe ser un número entero");
        }

        $correlativoFinal = $correlativoInicial + $cantidad - 1;

        return $this->repo->crearLoteCorrelativo(
            (int) $alta->id_bodega_destino, (int) $alta->id_producto,
            $datosLote['serie'] ?? null, $datosLote['resolucion'] ?? null, $datosLote['fecha_resolucion'] ?? null,
            $correlativoInicial, $correlativoFinal, $cantidad, (int) $alta->id, $idUsuarioSesion,
            $alta->precio_unitario !== null ? (float) $alta->precio_unitario : null,
        );
    }
}