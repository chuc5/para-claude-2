<?php

namespace App\inventarioApi\Helpers;

use PDO;
use RuntimeException;

/**
 * ReversaHelper
 *
 * Lógica interna de reversa TOTAL de entregas (no parciales).
 * Devuelve la mercancía entregada a sus lotes de origen y asienta el
 * movimiento tipo 3 (Alta por reversa) por cada lote restaurado.
 *
 * Estrategia por tipo de producto:
 *   - Correlativo (tipo 1): restaura sobre el lote ORIGINAL usando los rangos
 *     guardados en solicitudes_detalle (par principal + par _2 si la entrega
 *     cruzó dos lotes). Solo es válido si el rango es contiguo con el puntero
 *     correlativo_siguiente; si hay correlativos posteriores emitidos del mismo
 *     lote (hueco no representable), se rechaza.
 *   - Expiración (tipo 2) y Normal (tipo 3): lee solicitudes_detalle_lotes y
 *     devuelve cada cantidad a su lote exacto (preserva PEPS/FIFO y fechas).
 *
 * Todos los métodos deben ejecutarse dentro de una transacción abierta por la
 * clase principal. Este helper nunca hace commit ni rollback.
 *
 * Dependencias: MovimientoHelper.
 */
class ReversaHelper
{
    private PDO              $connect;
    private string           $idUsuario;
    private MovimientoHelper $movimientoHelper;

    /** Unidad fija usada por los movimientos de productos correlativos. */
    private const UNIDAD_CORRELATIVO = 1;

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
    // CORRELATIVO (tipo 1)
    // =========================================================================

    /**
     * Revierte una entrega de producto correlativo restaurando sobre el lote
     * original. Procesa el par principal y, si existe, el par secundario (_2).
     *
     * @param object $detalle  Fila de solicitudes_detalle (con rangos y lotes)
     * @param int    $idBodega
     * @param int    $idProducto
     * @param int    $idDetalle
     * @return float Total de correlativos restaurados
     *
     * @throws RuntimeException si algún rango no es contiguo con el puntero
     *                          (hay correlativos posteriores emitidos = hueco)
     */
    public function revertirEntregaCorrelativo(
        object $detalle, int $idBodega, int $idProducto, int $idDetalle
    ): float {
        $total = 0.0;

        // Par principal
        $total += $this->_restaurarRangoCorrelativo(
            (int)$detalle->id_lote_correlativo,
            (int)$detalle->correlativo_inicial_asignado,
            (int)$detalle->correlativo_final_asignado,
            $idBodega, $idProducto, $idDetalle
        );

        // Par secundario (solo si la entrega cruzó dos lotes)
        if ($detalle->id_lote_correlativo_2 !== null) {
            $total += $this->_restaurarRangoCorrelativo(
                (int)$detalle->id_lote_correlativo_2,
                (int)$detalle->correlativo_inicial_asignado_2,
                (int)$detalle->correlativo_final_asignado_2,
                $idBodega, $idProducto, $idDetalle
            );
        }

        return $total;
    }

    /**
     * Restaura un único rango [ini..fin] sobre su lote correlativo.
     * Solo es válido si fin + 1 == correlativo_siguiente (rango contiguo,
     * sin correlativos emitidos después en ese lote).
     */
    private function _restaurarRangoCorrelativo(
        int $idLote, int $ini, int $fin,
        int $idBodega, int $idProducto, int $idDetalle
    ): float {
        $n = $fin - $ini + 1;

        // Bloquear el lote y leer su puntero actual
        $stmt = $this->connect->prepare(
            "SELECT correlativo_siguiente
             FROM   bodega_inventario.lotes_correlativo
             WHERE  id = ?
             FOR UPDATE"
        );
        $stmt->execute([$idLote]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            throw new RuntimeException(
                "No se puede revertir: el lote correlativo {$idLote} ya no existe"
            );
        }

        // Validar contigüidad con el puntero (caso normal sin hueco)
        if (($fin + 1) !== (int)$lote['correlativo_siguiente']) {
            throw new RuntimeException(
                "No se puede revertir: hay correlativos posteriores emitidos del lote {$idLote}. " .
                "Revierta primero las entregas más recientes de ese lote."
            );
        }

        // Devolver el rango: sube disponible y retrocede el puntero
        $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_correlativo
             SET    cantidad_disponible   = cantidad_disponible   + ?,
                    correlativo_siguiente = correlativo_siguiente - ?
             WHERE  id = ?"
        )->execute([$n, $n, $idLote]);

        // Movimiento tipo 3 (Alta por reversa) con el rango restaurado
        $this->movimientoHelper->registrarReversaEntrega(
            $idBodega, $idProducto, self::UNIDAD_CORRELATIVO,
            (float)$n, $idDetalle, $ini, $fin
        );

        return (float)$n;
    }

    // =========================================================================
    // EXPIRACIÓN (tipo 2) Y NORMAL (tipo 3)
    // =========================================================================

    /**
     * Revierte una entrega de producto con expiración o normal devolviendo
     * cada cantidad a su lote exacto según solicitudes_detalle_lotes.
     *
     * @return float Total restaurado (suma de las cantidades de los lotes)
     */
    public function revertirEntregaPorLotes(
        int $idDetalle, int $idBodega, int $idProducto, int $idUnidad
    ): float {
        $stmt = $this->connect->prepare(
            "SELECT id_lote_exp, id_lote_normal, cantidad
             FROM   bodega_inventario.solicitudes_detalle_lotes
             WHERE  id_solicitud_det = ?
               AND  id_lote_corr IS NULL"
        );
        $stmt->execute([$idDetalle]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0;

        $stmtNormal = $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_normal
             SET    cantidad_disponible = cantidad_disponible + ?
             WHERE  id = ?"
        );
        $stmtExp = $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_expiracion
             SET    cantidad_disponible = cantidad_disponible + ?
             WHERE  id = ?"
        );

        foreach ($filas as $f) {
            $cantidad = (float)$f['cantidad'];

            if ($f['id_lote_normal'] !== null) {
                $stmtNormal->execute([$cantidad, (int)$f['id_lote_normal']]);
            } elseif ($f['id_lote_exp'] !== null) {
                $stmtExp->execute([$cantidad, (int)$f['id_lote_exp']]);
            } else {
                continue; // fila inconsistente, se ignora
            }

            // Movimiento tipo 3 (Alta por reversa) por cada lote restaurado
            $this->movimientoHelper->registrarReversaEntrega(
                $idBodega, $idProducto, $idUnidad,
                $cantidad, $idDetalle
            );

            $total += $cantidad;
        }

        return $total;
    }
}