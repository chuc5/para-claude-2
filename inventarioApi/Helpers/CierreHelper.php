<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * CierreHelper
 *
 * Consultas relacionadas con los cierres mensuales del módulo de inventario.
 * Se usa para impedir la reversa (eliminación/edición) de lotes y
 * movimientos anteriores al último cierre contable registrado.
 *
 * Dependencias: ninguna (helper base).
 */
class CierreHelper
{
    private PDO $connect;

    /**
     * @param PDO $connect  Conexión PDO compartida con la clase principal
     */
    public function __construct(PDO $connect)
    {
        $this->connect = $connect;
    }

    // =========================================================================
    // CONSULTAS DE CIERRE
    // =========================================================================

    /**
     * Devuelve la fecha de corte del último cierre mensual registrado.
     *
     * Usado por eliminarLote (y validaciones similares) para impedir
     * revertir lotes/movimientos ingresados antes del cierre.
     *
     * @return string|null  Fecha (YYYY-MM-DD HH:MM:SS) o null si no hay cierres
     */
    public function obtenerUltimoCierre(): ?string
    {
        try {
            $stmt = $this->connect->query(
                "SELECT fecha_corte
                 FROM   bodega_inventario.cierres_mensuales
                 ORDER  BY fecha_corte DESC
                 LIMIT  1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['fecha_corte'] : null;
        } catch (Exception $e) {
            error_log("[CierreHelper] obtenerUltimoCierre: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verifica si una fecha dada es posterior al último cierre mensual.
     * Útil para validar si un lote o movimiento puede eliminarse/editarse.
     *
     * @param string $fechaLote  Fecha del lote (cualquier formato que acepte strtotime)
     * @return bool  true si la fecha es posterior al cierre (o no hay cierres)
     */
    public function esPosteriorAlUltimoCierre(string $fechaLote): bool
    {
        $ultimoCierre = $this->obtenerUltimoCierre();

        if ($ultimoCierre === null) {
            return true; // Sin cierres: siempre se puede
        }

        return strtotime($fechaLote) > strtotime($ultimoCierre);
    }
}