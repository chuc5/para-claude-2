<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * LotesHelper
 *
 * Lógica de consumo de lotes para las tres estrategias del sistema:
 *   - FIFO    → lotes_normal    (fecha_ingreso ASC)
 *   - PEPS    → lotes_expiracion (fecha_expiracion ASC)
 *   - Correlativo → lotes_correlativo (correlativo_inicial ASC)
 *
 * Todos los métodos públicos deben ejecutarse dentro de una transacción
 * abierta por la clase principal. Este helper nunca hace commit ni rollback.
 *
 * Dependencias: MovimientoHelper.
 */
class LotesHelper
{
    private PDO             $connect;
    private string          $idUsuario;
    private MovimientoHelper $movimientoHelper;

    /**
     * @param PDO             $connect          Conexión PDO compartida
     * @param string          $idUsuario         ID del usuario en sesión
     * @param MovimientoHelper $movimientoHelper Helper de movimientos
     */
    public function __construct(
        PDO $connect,
        string $idUsuario,
        MovimientoHelper $movimientoHelper
    ) {
        $this->connect          = $connect;
        $this->idUsuario        = $idUsuario;
        $this->movimientoHelper = $movimientoHelper;
    }

    // =========================================================================
    // FIFO — lotes_normal
    // =========================================================================

    /**
     * Aplica FIFO sobre lotes_normal consumiendo de los más antiguos primero.
     * Puede consumir de múltiples lotes si el primero no alcanza.
     *
     * @param int         $idBodega
     * @param int         $idProducto
     * @param int         $idUnidad
     * @param float       $cantidadPedida
     * @param int         $idDetalle      ID de solicitudes_detalle (trazabilidad)
     * @param string|null $idReceptor     ID del usuario receptor
     * @return float                       Total efectivamente consumido
     */
    public function aplicarFIFO(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidadPedida, int $idDetalle, ?string $idReceptor = null,
        int $tipoMovimiento = 5
    ): float {
        $stmt = $this->connect->prepare(
            "SELECT id, cantidad_disponible
         FROM   bodega_inventario.lotes_normal
         WHERE  id_bodega         = ?
           AND  id_producto       = ?
           AND  id_unidad         = ?
           AND  cantidad_disponible > 0
         ORDER  BY fecha_ingreso ASC
         FOR UPDATE"
        );
        $stmt->execute([$idBodega, $idProducto, $idUnidad]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->_consumirLotes(
            $lotes, 'lotes_normal', 'id_lote_normal',
            $idBodega, $idProducto, $idUnidad,
            $cantidadPedida, $idDetalle, $idReceptor, $tipoMovimiento
        );
    }

    // =========================================================================
    // PEPS — lotes_expiracion
    // =========================================================================

    /**
     * Aplica PEPS sobre lotes_expiracion consumiendo los que expiran antes primero.
     *
     * @param int         $idBodega
     * @param int         $idProducto
     * @param int         $idUnidad
     * @param float       $cantidadPedida
     * @param int         $idDetalle
     * @param string|null $idReceptor
     * @return float       Total efectivamente consumido
     */
    public function aplicarPEPS(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidadPedida, int $idDetalle, ?string $idReceptor = null,
        int $tipoMovimiento = 5
    ): float {
        $stmt = $this->connect->prepare(
            "SELECT id, (cantidad_disponible - cantidad_reservada) AS cantidad_disponible
            FROM   bodega_inventario.lotes_expiracion
            WHERE  id_bodega   = ?
            AND  id_producto = ?
            AND  id_unidad   = ?
            AND  (cantidad_disponible - cantidad_reservada) > 0
            ORDER  BY fecha_expiracion ASC
            FOR UPDATE"
        );
        $stmt->execute([$idBodega, $idProducto, $idUnidad]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->_consumirLotes(
            $lotes, 'lotes_expiracion', 'id_lote_exp',
            $idBodega, $idProducto, $idUnidad,
            $cantidadPedida, $idDetalle, $idReceptor, $tipoMovimiento
        );
    }

    // =========================================================================
    // CORRELATIVO
    // =========================================================================

    /**
     * Asigna correlativos a una entrega, consumiendo lotes en orden ascendente.
     * Puede cruzar hasta 2 lotes si el primero no alcanza.
     *
     * @param int         $idBodega
     * @param int         $idProducto
     * @param int         $cantidad      Siempre entero para correlativos
     * @param int         $idDetalle
     * @param string|null $idReceptor
     * @return array  [
     *   'exito'              => bool,
     *   'mensaje'            => string|null,
     *   'correlativo_inicial'=> int|null,
     *   'correlativo_final'  => int|null,
     *   'lotes_usados'       => array,
     * ]
     */
    public function asignarCorrelativo(
        int $idBodega, int $idProducto,
        int $cantidad, int $idDetalle, ?string $idReceptor = null,
        int $tipoMovimiento = 5
    ): array {
        $stmt = $this->connect->prepare(
            "SELECT id, correlativo_siguiente, correlativo_final,
        (cantidad_disponible - cantidad_reservada) AS cantidad_disponible
            FROM   bodega_inventario.lotes_correlativo
            WHERE  id_bodega   = ?
            AND  id_producto = ?
            AND  (cantidad_disponible - cantidad_reservada) > 0
            ORDER  BY correlativo_inicial ASC
            FOR UPDATE"
        );
        $stmt->execute([$idBodega, $idProducto]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalDisponible = array_sum(array_column($lotes, 'cantidad_disponible'));
        if ($totalDisponible < $cantidad) {
            return [
                'exito'   => false,
                'mensaje' => "Stock insuficiente. Disponible: {$totalDisponible}, solicitado: {$cantidad}",
            ];
        }

        $pendiente   = $cantidad;
        $lotesUsados = [];

        $stmtUpd = $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_correlativo
         SET    cantidad_disponible   = cantidad_disponible   - ?,
                correlativo_siguiente = correlativo_siguiente + ?
         WHERE  id = ?"
        );

        foreach ($lotes as $lote) {
            if ($pendiente <= 0) break;

            $consumir = min($pendiente, (int)$lote['cantidad_disponible']);
            $corrIni  = (int)$lote['correlativo_siguiente'];
            $corrFin  = $corrIni + $consumir - 1;

            $stmtUpd->execute([$consumir, $consumir, $lote['id']]);

            // Antes: llamaba sin el último argumento (usaba default 5) -- ahora pasa $tipoMovimiento
            $this->movimientoHelper->registrarBajaEntregaCorrelativo(
                $idBodega, $idProducto,
                $consumir, $idDetalle, $idReceptor,
                $corrIni, $corrFin, $tipoMovimiento
            );

            $this->_insertarDetalleLote($idDetalle, 'id_lote_corr', (int)$lote['id'], $consumir);

            $lotesUsados[] = [
                'id_lote'  => (int)$lote['id'],
                'cantidad' => $consumir,
                'corr_ini' => $corrIni,
                'corr_fin' => $corrFin,
            ];

            $pendiente -= $consumir;
        }

        $l1 = $lotesUsados[0];
        $l2 = $lotesUsados[1] ?? null;

        $this->connect->prepare(
            "UPDATE bodega_inventario.solicitudes_detalle
         SET    correlativo_inicial_asignado   = ?,
                correlativo_final_asignado     = ?,
                id_lote_correlativo            = ?,
                correlativo_inicial_asignado_2 = ?,
                correlativo_final_asignado_2   = ?,
                id_lote_correlativo_2          = ?,
                cantidad_entregada             = ?,
                id_usuario_gestion             = ?,
                fecha_gestion                  = NOW()
         WHERE  id = ?"
        )->execute([
            $l1['corr_ini'], $l1['corr_fin'], $l1['id_lote'],
            $l2['corr_ini'] ?? null, $l2['corr_fin'] ?? null, $l2['id_lote'] ?? null,
            $cantidad,
            $this->idUsuario,
            $idDetalle,
        ]);

        return [
            'exito'               => true,
            'mensaje'             => null,
            'correlativo_inicial' => $l1['corr_ini'],
            'correlativo_final'   => $l1['corr_fin'],
            'lotes_usados'        => $lotesUsados,
        ];
    }

    // =========================================================================
    // HELPER PRIVADO — CONSUMO GENÉRICO DE LOTES
    // =========================================================================

    /**
     * Lógica genérica de consumo compartida por FIFO y PEPS.
     * Itera los lotes ya ordenados, descuenta de cada uno y registra
     * el movimiento tipo 5 (Baja por entrega).
     *
     * @param array       $lotes        Lotes ordenados por FIFO o PEPS
     * @param string      $tablaLotes   'lotes_normal' | 'lotes_expiracion'
     * @param string      $campoFk      Campo FK en solicitudes_detalle_lotes
     * @param int         $idBodega
     * @param int         $idProducto
     * @param int         $idUnidad
     * @param float       $cantidadPedida
     * @param int         $idDetalle
     * @param string|null $idReceptor
     * @return float       Total efectivamente consumido
     */
    private function _consumirLotes(
        array $lotes, string $tablaLotes, string $campoFk,
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidadPedida, int $idDetalle, ?string $idReceptor,
        int $tipoMovimiento = 5
    ): float {
        $pendiente      = $cantidadPedida;
        $consumidoTotal = 0.0;

        $stmtUpd = $this->connect->prepare(
            "UPDATE bodega_inventario.{$tablaLotes}
         SET    cantidad_disponible = cantidad_disponible - ?
         WHERE  id = ?"
        );

        foreach ($lotes as $lote) {
            if ($pendiente <= 0) break;

            $consumir = min($pendiente, (float)$lote['cantidad_disponible']);

            $stmtUpd->execute([$consumir, $lote['id']]);

            // Antes: $this->movimientoHelper->registrar(5, ...) -- ahora usa el tipo recibido
            $this->movimientoHelper->registrar(
                $tipoMovimiento, $idBodega, $idProducto, $idUnidad,
                $consumir, 'solicitudes_detalle', $idDetalle,
                $idReceptor
            );

            $this->_insertarDetalleLote($idDetalle, $campoFk, (int)$lote['id'], $consumir);

            $pendiente      -= $consumir;
            $consumidoTotal += $consumir;
        }

        return $consumidoTotal;
    }

    /**
     * Inserta una fila de trazabilidad en solicitudes_detalle_lotes.
     *
     * @param int    $idDetalle  ID de solicitudes_detalle
     * @param string $campoFk    'id_lote_corr' | 'id_lote_exp' | 'id_lote_normal'
     * @param int    $idLote     ID del lote consumido
     * @param float  $cantidad
     */
    private function _insertarDetalleLote(
        int $idDetalle, string $campoFk, int $idLote, float $cantidad
    ): void {
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.solicitudes_detalle_lotes
                 (id_solicitud_det, {$campoFk}, cantidad)
             VALUES (?, ?, ?)"
        )->execute([$idDetalle, $idLote, $cantidad]);
    }
}