<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoBodega;
use App\inventarioApi\Helpers\BodegaHelper;
use App\inventarioApi\Repositories\CompraRepository;
use Exception;

/**
 * MESA DE TRABAJO — Encargado de Área
 *
 * Mismo motor que la mesa de Agencia, con tres diferencias reales:
 *   - Alcance: una sola bodega — la propia del encargado (sesión), no un
 *     conjunto de bodegas ajenas. No hay selector de bodega.
 *   - Sin "cambiar bodega destino": no hay a dónde redirigir, solo tiene
 *     la suya.
 *   - Cantidad: SÍ se puede subir (no solo bajar), y a propósito NO
 *     dispara autorización de Gerencia/Financiero — es decisión exclusiva
 *     del encargado (CompraService::ajustarCantidadLinea con
 *     $omitirAutorizacion = true).
 *
 * Además, acá SÍ existe "dar de alta directa": como el encargado es el
 * mismo que recibe físicamente, puede saltarse el módulo de Altas normal
 * y completar el ingreso en el mismo paso — pero solo para UN lote por
 * línea. Si el producto es de control Correlativo o Expiración, pide los
 * datos de ese lote; si necesita repartir la cantidad en más de un lote,
 * esta vía no aplica — debe hacerlo desde el módulo de Altas.
 */
final class MesaTrabajoAreaService
{
    public function __construct(
        private CompraRepository $repo,
        private CompraService $compraService,
        private BodegaHelper $bodegaHelper,
    ) {
    }

    // =====================================================================
    // Listado
    // =====================================================================

    /**
     * @param array{
     *   id_tipo_producto?: ?int, id_tipo_origen?: ?int,
     *   comprado?: ?bool, busqueda?: ?string
     * } $filtros
     */
    public function listar(array $filtros, array $estados, int $pagina, int $porPagina): array
    {
        $idBodega = $this->exigirBodegaPropia();

        $filtrosConBodega = $filtros + ['id_bodega_destino' => $idBodega];

        return $this->repo->listarLineasMesaTrabajo(TipoBodega::AREA->value, $filtrosConBodega, $estados, $pagina, $porPagina);
    }

    // =====================================================================
    // Ajustar cantidad — puede subir o bajar, nunca pide autorización
    // =====================================================================

    public function ajustarCantidad(int $idCompra, int $idLinea, float $cantidad): void
    {
        $idBodega = $this->exigirBodegaPropia();
        $this->verificarLineaPropia($idCompra, $idLinea, $idBodega);
        $this->compraService->ajustarCantidadLinea($idCompra, $idLinea, $cantidad, omitirAutorizacion: true);
    }

    // =====================================================================
    // Autoguardado de precio (borrador)
    // =====================================================================

    public function guardarPrecioBorrador(int $idCompra, int $idLinea, float $precio): void
    {
        $idBodega = $this->exigirBodegaPropia();

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
        if ((int) $linea->id_bodega_destino !== $idBodega) {
            throw new Exception('Esta línea no pertenece a su bodega de área');
        }
        if ((bool) $linea->comprado_con_precio) {
            throw new Exception('Esta línea ya fue confirmada como comprada, no admite más cambios de precio');
        }

        $this->repo->guardarPrecioBorrador($idLinea, $precio);
    }

    /**
     * @param array<array{id_compra:int,id_linea:int,precio_unitario:float}> $lineas
     * @return array{procesadas:int, omitidas:array<array{id_linea:int,motivo:string}>}
     */
    public function guardarPreciosBorradorLote(array $lineas): array
    {
        $this->exigirBodegaPropia();

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
                $this->guardarPrecioBorrador($idCompra, $idLinea, $precio);
                $procesadas++;
            } catch (Exception $e) {
                $omitidas[] = ['id_linea' => $idLinea, 'motivo' => $e->getMessage()];
            }
        }

        return ['procesadas' => $procesadas, 'omitidas' => $omitidas];
    }

    // =====================================================================
    // Registrar precios — en lote (deja en "Comprado", no envía sola)
    // =====================================================================

    /**
     * @param array<array{id_compra:int,id_linea:int,precio_unitario:float}> $lineas
     * @return array{procesadas:int, ids_altas:array<int>}
     */
    public function registrarPrecios(array $lineas, string $idUsuarioSesion): array
    {
        $idBodega = $this->exigirBodegaPropia();

        if (empty($lineas)) {
            throw new Exception('No se recibió ninguna línea para procesar');
        }

        $procesadas = 0;

        foreach ($lineas as $i => $l) {
            $idCompra = (int) ($l['id_compra'] ?? 0);
            $idLinea  = (int) ($l['id_linea'] ?? 0);
            $precio   = (float) ($l['precio_unitario'] ?? 0);

            if ($idCompra < 1 || $idLinea < 1 || $precio <= 0) {
                throw new Exception('Error de consistencia: la línea #' . ($i + 1) . ' tiene datos inválidos');
            }

            $this->verificarLineaPropia($idCompra, $idLinea, $idBodega);
            $this->compraService->marcarLineaComprada($idCompra, $idLinea, $precio, $idUsuarioSesion);
            $procesadas++;
        }

        return ['procesadas' => $procesadas, 'ids_altas' => []];
    }

    // =====================================================================
    // Enviar — deja la compra en "Enviada" sin registrar (por si el
    // encargado prefiere revisarla antes de cerrar el ciclo)
    // =====================================================================

    /** @return array{ids_altas: array<int>} */
    public function enviarCompra(int $idCompra, string $idUsuarioSesion): array
    {
        $idBodega = $this->exigirBodegaPropia();
        $this->verificarCompraPropia($idCompra, $idBodega);

        return ['ids_altas' => $this->compraService->enviar($idCompra, $idUsuarioSesion)];
    }

    // =====================================================================
    // Dar de alta directa — Enviar + ingresar UN lote por línea + Registrar
    // =====================================================================

    /**
     * Preview: líneas de la compra con lo necesario para armar el formulario
     * de "dar de alta directa" en el frontend — solo hace falta pedir datos
     * si hay productos tipo Correlativo o Expiración entre ellas.
     */
    public function obtenerRequisitosAlta(int $idCompra): array
    {
        $idBodega = $this->exigirBodegaPropia();
        $this->verificarCompraPropia($idCompra, $idBodega);

        $lineas = $this->repo->obtenerLineas($idCompra);

        return array_map(static fn ($l) => [
            'id_linea'            => (int) $l->id,
            'id_producto'         => (int) $l->id_producto,
            'producto'            => $l->producto,
            'id_tipo_producto'    => (int) $l->id_tipo_producto,
            'cantidad_final'      => (float) $l->cantidad_final,
            'abreviatura'         => $l->abreviatura,
            // Sugerencias — lo que el solicitante pidió originalmente, si lo indicó.
            'serie_sugerida'            => $l->serie,
            'resolucion_sugerida'       => $l->resolucion,
            'fecha_resolucion_sugerida' => $l->fecha_resolucion,
            'correlativo_inicial_sugerido' => $l->correlativo_inicial,
        ], $lineas);
    }

    /**
     * @param array<int, array{fecha_expiracion?:string, correlativo_inicial?:int, serie?:?string, resolucion?:?string, fecha_resolucion?:?string}> $datosLotePorProducto
     *   Indexado por id_producto — solo hace falta traer entradas para
     *   productos tipo Correlativo (correlativo_inicial) o Expiración
     *   (fecha_expiracion). Tipo Normal no necesita nada.
     * @return array{ids_altas: array<int>, ids_lotes: array<int>}
     */
    public function registrarAltaDirecta(int $idCompra, array $datosLotePorProducto, string $idUsuarioSesion): array
    {
        $idBodega = $this->exigirBodegaPropia();
        $this->verificarCompraPropia($idCompra, $idBodega);

        // Paso 1: enviar (genera las altas — exige que la compra ya esté "Comprada").
        $idsAltas = $this->compraService->enviar($idCompra, $idUsuarioSesion);

        // Paso 2: completar cada alta con UN lote.
        $altas    = $this->repo->obtenerAltasPorCompra($idCompra);
        $idsLotes = [];

        foreach ($altas as $alta) {
            $idLote = $this->crearLotePorTipo($alta, $datosLotePorProducto[(int) $alta->id_producto] ?? [], $idUsuarioSesion);
            $idsLotes[] = $idLote;
        }

        // Paso 3: registrar — cierre del ciclo, sin pedir datos adicionales.
        $this->compraService->registrar($idCompra);

        return ['ids_altas' => $idsAltas, 'ids_lotes' => $idsLotes];
    }

    private function crearLotePorTipo(object $alta, array $datosLote, string $idUsuarioSesion): int
    {
        $idBodega   = (int) $alta->id_bodega_destino;
        $idProducto = (int) $alta->id_producto;
        $idUnidad   = (int) $alta->id_unidad;
        $cantidad   = (float) $alta->cantidad_enviada;
        $precio     = $alta->precio_unitario !== null ? (float) $alta->precio_unitario : null;

        $idLote = match ((int) $alta->id_tipo_producto) {
            1 => $this->crearLoteCorrelativo($alta, $datosLote, $idUsuarioSesion), // Correlativo
            2 => $this->crearLoteExpiracionDesdeAlta($alta, $datosLote, $idUsuarioSesion), // Expiración
            default => $this->repo->crearLoteNormal($idBodega, $idProducto, $idUnidad, $cantidad, (int) $alta->id, $idUsuarioSesion, $precio),
        };

        $tablaLote = match ((int) $alta->id_tipo_producto) {
            1 => 'lotes_correlativo',
            2 => 'lotes_expiracion',
            default => 'lotes_normal',
        };

        $this->repo->incrementarStockPorAlta($idBodega, $idProducto, $idUnidad, $cantidad);
        $this->repo->registrarMovimientoAltaCompra($idBodega, $idProducto, $idUnidad, $cantidad, $tablaLote, $idLote, $idUsuarioSesion, $precio);
        $this->repo->ingresarLoteEnAlta((int) $alta->id, (float) $alta->cantidad_ingresada, $cantidad, $cantidad);

        return $idLote;
    }

    private function crearLoteExpiracionDesdeAlta(object $alta, array $datosLote, string $idUsuarioSesion): int
    {
        $fecha = $datosLote['fecha_expiracion'] ?? null;
        if (!$fecha) {
            throw new Exception("El producto '{$alta->producto}' requiere fecha de expiración para dar de alta directa (o use 'Enviar' y complete el ingreso desde Altas si necesita varios lotes)");
        }

        return $this->repo->crearLoteExpiracion(
            (int) $alta->id_bodega_destino, (int) $alta->id_producto, (int) $alta->id_unidad,
            $fecha, (float) $alta->cantidad_enviada, (int) $alta->id, $idUsuarioSesion,
            $alta->precio_unitario !== null ? (float) $alta->precio_unitario : null,
        );
    }

    private function crearLoteCorrelativo(object $alta, array $datosLote, string $idUsuarioSesion): int
    {
        $correlativoInicial = isset($datosLote['correlativo_inicial']) ? (int) $datosLote['correlativo_inicial'] : null;
        if (!$correlativoInicial || $correlativoInicial < 1) {
            throw new Exception("El producto '{$alta->producto}' requiere el correlativo inicial para dar de alta directa (o use 'Enviar' y complete el ingreso desde Altas si necesita varios lotes)");
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

    // -----------------------------------------------------------------

    /** @return int id_bodega propia del encargado, o lanza si no aplica. */
    private function exigirBodegaPropia(): int
    {
        $idBodega = $this->bodegaHelper->obtenerBodegaDelEncargado();
        if ($idBodega === null) {
            throw new Exception('Solo el encargado asignado a una bodega de área puede operar esta mesa de trabajo');
        }

        return $idBodega;
    }

    private function verificarLineaPropia(int $idCompra, int $idLinea, int $idBodega): void
    {
        $linea = $this->repo->obtenerLineaConBloqueo($idCompra, $idLinea);
        if (!$linea || (int) $linea->id_bodega_destino !== $idBodega) {
            throw new Exception('Esta línea no pertenece a su bodega de área');
        }
    }

    private function verificarCompraPropia(int $idCompra, int $idBodega): void
    {
        $lineas = $this->repo->obtenerLineas($idCompra);
        if (empty($lineas) || (int) $lineas[0]->id_bodega_destino !== $idBodega) {
            throw new Exception('Esta compra no pertenece a su bodega de área');
        }
    }
}