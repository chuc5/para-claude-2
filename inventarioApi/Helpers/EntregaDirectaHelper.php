<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * EntregaDirectaHelper
 *
 * Flujo de Entrega Directa (Administrador de Bodega de Área / Agencia
 * habilitada, sin solicitud previa). Reutiliza StockHelper y LotesHelper
 * tal como están; la única diferencia frente al flujo normal es que:
 *   - No hay reserva previa (cantidad_reservada afectada = 0)
 *   - La cabecera de `solicitudes` nace directamente en estado Entregada (2)
 *     con es_entrega_directa = 1
 *   - Los movimientos se registran con tipo 7 (Baja por entrega directa)
 *     en vez de 5
 *
 * `confirmar()` debe ejecutarse dentro de una transacción abierta por la
 * clase principal (igual que el resto de helpers). `simular()` es de solo
 * lectura y no requiere transacción.
 *
 * Dependencias: StockHelper, LotesHelper, BodegaHelper.
 */
class EntregaDirectaHelper
{
    private const TIPO_CORRELATIVO = 1;
    private const TIPO_EXPIRACION  = 2;
    private const TIPO_NORMAL      = 3;

    /** Tipo de movimiento propio de este flujo (ver tipos_movimiento). */
    private const TIPO_MOVIMIENTO_ENTREGA_DIRECTA = 7;

    private PDO $connect;
    private string $idUsuario;
    private StockHelper $stockHelper;
    private LotesHelper $lotesHelper;
    private BodegaHelper $bodegaHelper;

    public function __construct(
        PDO $connect,
        string $idUsuario,
        StockHelper $stockHelper,
        LotesHelper $lotesHelper,
        BodegaHelper $bodegaHelper
    ) {
        $this->connect      = $connect;
        $this->idUsuario    = $idUsuario;
        $this->stockHelper  = $stockHelper;
        $this->lotesHelper  = $lotesHelper;
        $this->bodegaHelper = $bodegaHelper;
    }

    // =========================================================================
    // VALIDACIÓN DE BODEGA
    // =========================================================================

    /**
     * Verifica que la bodega exista, esté activa y tenga habilitada la
     * Entrega Directa. Lanza excepción con mensaje listo para mostrar
     * si no cumple.
     */
    public function validarBodegaHabilitada(int $idBodega): void
    {
        $bodega = $this->bodegaHelper->obtenerInfoBodega($idBodega);

        if (!$bodega) {
            throw new Exception('La bodega indicada no existe o se encuentra inactiva');
        }

        if ((int)$bodega->permite_entrega_directa !== 1) {
            throw new Exception('Esta bodega no tiene habilitado el flujo de Entrega Directa');
        }
    }

    // =========================================================================
    // PREVISUALIZACIÓN (sin escribir nada)
    // =========================================================================

    /**
     * Muestra qué lotes/rangos se consumirían para una cantidad dada,
     * sin bloquear ni modificar nada. Útil para el resumen de confirmación.
     *
     * @return array {suficiente: bool, disponible: float, desglose: array}
     */
    public function simular(int $idBodega, int $idProducto, int $idUnidad, int $idTipo, float $cantidad): array
    {
        $stmt = $this->connect->prepare(
            "SELECT cantidad_disponible
             FROM   bodega_inventario.stock
             WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?"
        );
        $stmt->execute([$idBodega, $idProducto, $idUnidad]);
        $disponible = (float)($stmt->fetchColumn() ?: 0);

        if ($cantidad > $disponible) {
            return ['suficiente' => false, 'disponible' => $disponible, 'desglose' => []];
        }

        $desglose = match ($idTipo) {
            self::TIPO_CORRELATIVO => $this->_previsualizarCorrelativo($idBodega, $idProducto, (int)$cantidad),
            self::TIPO_EXPIRACION  => $this->_previsualizarPorLote('lotes_expiracion', 'fecha_expiracion', $idBodega, $idProducto, $idUnidad, $cantidad, true),
            default                => $this->_previsualizarPorLote('lotes_normal', 'fecha_ingreso', $idBodega, $idProducto, $idUnidad, $cantidad, false),
        };

        return ['suficiente' => true, 'disponible' => $disponible, 'desglose' => $desglose];
    }

    private function _previsualizarPorLote(
        string $tabla, string $campoOrden,
        int $idBodega, int $idProducto, int $idUnidad, float $cantidad, bool $restarReservada
    ): array {
        $columnaDisponible = $restarReservada
            ? '(cantidad_disponible - cantidad_reservada) AS cantidad_disponible'
            : 'cantidad_disponible';

        $stmt = $this->connect->prepare(
            "SELECT id, {$columnaDisponible}, {$campoOrden}
             FROM   bodega_inventario.{$tabla}
             WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?
               AND  " . ($restarReservada ? '(cantidad_disponible - cantidad_reservada) > 0' : 'cantidad_disponible > 0') . "
             ORDER  BY {$campoOrden} ASC"
        );
        $stmt->execute([$idBodega, $idProducto, $idUnidad]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pendiente = $cantidad;
        $desglose  = [];
        foreach ($lotes as $lote) {
            if ($pendiente <= 0) break;
            $tomar = min($pendiente, (float)$lote['cantidad_disponible']);
            $desglose[] = [
                'id_lote'   => (int)$lote['id'],
                $campoOrden => $lote[$campoOrden],
                'cantidad'  => $tomar,
            ];
            $pendiente -= $tomar;
        }

        return $desglose;
    }

    private function _previsualizarCorrelativo(int $idBodega, int $idProducto, int $cantidad): array
    {
        $stmt = $this->connect->prepare(
            "SELECT id, correlativo_siguiente, correlativo_final,
                    (cantidad_disponible - cantidad_reservada) AS cantidad_disponible
             FROM   bodega_inventario.lotes_correlativo
             WHERE  id_bodega = ? AND id_producto = ?
               AND  (cantidad_disponible - cantidad_reservada) > 0
             ORDER  BY correlativo_inicial ASC"
        );
        $stmt->execute([$idBodega, $idProducto]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pendiente = $cantidad;
        $desglose  = [];
        foreach ($lotes as $lote) {
            if ($pendiente <= 0) break;
            $tomar   = min($pendiente, (int)$lote['cantidad_disponible']);
            $corrIni = (int)$lote['correlativo_siguiente'];
            $corrFin = $corrIni + $tomar - 1;
            $desglose[] = [
                'id_lote'  => (int)$lote['id'],
                'corr_ini' => $corrIni,
                'corr_fin' => $corrFin,
                'cantidad' => $tomar,
            ];
            $pendiente -= $tomar;
        }

        return $desglose;
    }

    // =========================================================================
    // CONFIRMACIÓN (transaccional — debe llamarse dentro de beginTransaction)
    // =========================================================================

    /**
     * Ejecuta la entrega directa completa: crea la cabecera+detalle en
     * `solicitudes` (ya en estado Entregada) y descuenta físicamente el
     * inventario aplicando FIFO/PEPS/Correlativo según el tipo de producto.
     *
     * @param int    $idBodega
     * @param string $idReceptor    Usuario que recibe (dbintranet.usuarios.idUsuarios)
     * @param int    $idProducto
     * @param int    $idUnidad
     * @param int    $idTipo        Tipo de producto (1|2|3)
     * @param float  $cantidad
     * @param string $motivo        Justificación obligatoria (ya validada >= 10 caracteres por el endpoint)
     * @return array {id_solicitud: int, id_solicitud_detalle: int, cantidad_entregada: float}
     * @throws Exception  Si no hay stock suficiente o falla la asignación de correlativos
     */
    public function confirmar(
        int $idBodega, string $idReceptor,
        int $idProducto, int $idUnidad, int $idTipo,
        float $cantidad, string $motivo
    ): array {
        // 1. Bloqueo pesimista + validación de disponible
        $stock = $this->stockHelper->obtenerConBloqueo($idBodega, $idProducto, $idUnidad);
        if (!$stock) {
            throw new Exception('No existen registros de stock inicializados para este producto en la bodega');
        }
        if ($cantidad > (float)$stock['cantidad_disponible']) {
            throw new Exception("Stock insuficiente. Disponible actual: {$stock['cantidad_disponible']}");
        }

        // 2. Cabecera: nace directo en Entregada (2), es_entrega_directa = 1
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.solicitudes
                 (id_usuario, id_bodega, id_estado, es_entrega_directa, observaciones, created_at)
             VALUES (?, ?, 2, 1, ?, CURRENT_TIMESTAMP)"
        )->execute([$idReceptor, $idBodega, $motivo]);

        $idSolicitud = (int)$this->connect->lastInsertId();

        // 3. Detalle
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.solicitudes_detalle
                 (id_solicitud, id_producto, id_unidad, cantidad_solicitada)
             VALUES (?, ?, ?, ?)"
        )->execute([$idSolicitud, $idProducto, $idUnidad, $cantidad]);

        $idDetalle = (int)$this->connect->lastInsertId();

        // 4. Consumo físico según tipo de producto (tipo de movimiento 7)
        if ($idTipo === self::TIPO_CORRELATIVO) {
            $resultado = $this->lotesHelper->asignarCorrelativo(
                $idBodega, $idProducto, (int)$cantidad, $idDetalle, $idReceptor,
                self::TIPO_MOVIMIENTO_ENTREGA_DIRECTA
            );

            if (!($resultado['exito'] ?? false)) {
                throw new Exception($resultado['mensaje'] ?? 'No fue posible asignar los correlativos');
            }

            $this->stockHelper->descontarPorEntrega($idBodega, $idProducto, $idUnidad, $cantidad, 0);
            $consumido = $cantidad;
        } else {
            $consumido = $idTipo === self::TIPO_EXPIRACION
                ? $this->lotesHelper->aplicarPEPS($idBodega, $idProducto, $idUnidad, $cantidad, $idDetalle, $idReceptor, self::TIPO_MOVIMIENTO_ENTREGA_DIRECTA)
                : $this->lotesHelper->aplicarFIFO($idBodega, $idProducto, $idUnidad, $cantidad, $idDetalle, $idReceptor, self::TIPO_MOVIMIENTO_ENTREGA_DIRECTA);

            $this->stockHelper->descontarPorEntrega($idBodega, $idProducto, $idUnidad, $consumido, 0);

            // Deja constancia de quién gestionó (el admin que entrega), igual que en entregarSolicitud
            $this->connect->prepare(
                "UPDATE bodega_inventario.solicitudes_detalle
                 SET    cantidad_entregada  = ?,
                        id_usuario_gestion  = ?,
                        fecha_gestion       = CURRENT_TIMESTAMP
                 WHERE  id = ?"
            )->execute([$consumido, $this->idUsuario, $idDetalle]);
        }

        return [
            'id_solicitud'          => $idSolicitud,
            'id_solicitud_detalle'  => $idDetalle,
            'cantidad_entregada'    => $consumido,
        ];
    }
}