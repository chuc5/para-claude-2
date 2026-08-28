<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * StockHelper
 *
 * Operaciones atómicas reutilizables sobre la tabla stock.
 * Todas deben ejecutarse dentro de una transacción abierta por la clase principal.
 * Este helper nunca hace commit ni rollback.
 *
 * Dependencias: MovimientoHelper.
 */
class StockHelper
{
    private PDO             $connect;
    private MovimientoHelper $movimientoHelper;

    /**
     * @param PDO             $connect          Conexión PDO compartida
     * @param MovimientoHelper $movimientoHelper Helper de movimientos
     */
    public function __construct(PDO $connect, MovimientoHelper $movimientoHelper)
    {
        $this->connect          = $connect;
        $this->movimientoHelper = $movimientoHelper;
    }

    // =========================================================================
    // ALTAS (incremento de stock)
    // =========================================================================

    /**
     * Incrementa cantidad_total al ingresar un lote de alta.
     * Usa INSERT … ON DUPLICATE KEY UPDATE para ser atómico y evitar
     * race conditions sin necesidad de SELECT previo.
     *
     * @param int   $idBodega
     * @param int   $idProducto
     * @param int   $idUnidad
     * @param float $cantidad
     */
    public function incrementarPorAlta(
        int $idBodega, int $idProducto, int $idUnidad, float $cantidad
    ): void {
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.stock
                 (id_bodega, id_producto, id_unidad, cantidad_total, cantidad_reservada)
             VALUES (?, ?, ?, ?, 0.00)
             ON DUPLICATE KEY UPDATE cantidad_total = cantidad_total + ?"
        )->execute([$idBodega, $idProducto, $idUnidad, $cantidad, $cantidad]);
    }

    // =========================================================================
    // RESERVAS (solicitudes)
    // =========================================================================

    /**
     * Incrementa cantidad_reservada al crear una solicitud.
     * Debe llamarse después de validar que hay stock suficiente.
     *
     * @param int   $idBodega
     * @param int   $idProducto
     * @param int   $idUnidad
     * @param float $cantidad
     */
    public function incrementarReserva(
        int $idBodega, int $idProducto, int $idUnidad, float $cantidad
    ): void {
        $this->connect->prepare(
            "UPDATE bodega_inventario.stock
             SET    cantidad_reservada = cantidad_reservada + ?,
                    updated_at         = NOW()
             WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?"
        )->execute([$cantidad, $idBodega, $idProducto, $idUnidad]);
    }

    /**
     * Libera la reserva al cancelar o rechazar una solicitud.
     * GREATEST(0, ...) protege contra valores negativos por inconsistencias.
     *
     * @param int   $idBodega
     * @param int   $idProducto
     * @param int   $idUnidad
     * @param float $cantidad
     */
    public function liberarReserva(
        int $idBodega, int $idProducto, int $idUnidad, float $cantidad
    ): void {
        $this->connect->prepare(
            "UPDATE bodega_inventario.stock
             SET    cantidad_reservada = GREATEST(0, cantidad_reservada - ?),
                    updated_at         = NOW()
             WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?"
        )->execute([$cantidad, $idBodega, $idProducto, $idUnidad]);
    }

    // =========================================================================
    // ENTREGAS (baja de stock)
    // =========================================================================

    /**
     * Descuenta de cantidad_total y cantidad_reservada al entregar una solicitud.
     * GREATEST(0, ...) en ambas columnas como protección extra.
     *
     * @param int   $idBodega
     * @param int   $idProducto
     * @param int   $idUnidad
     * @param float $cantidadEntregada  Cantidad efectivamente consumida de los lotes
     * @param float $cantidadReservada  Cantidad que estaba reservada en la solicitud
     */
    public function descontarPorEntrega(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidadEntregada, float $cantidadReservada
    ): void {
        $this->connect->prepare(
            "UPDATE bodega_inventario.stock
             SET cantidad_total     = GREATEST(0, cantidad_total     - ?),
                 cantidad_reservada = GREATEST(0, cantidad_reservada - ?),
                 updated_at         = NOW()
             WHERE id_bodega = ? AND id_producto = ? AND id_unidad = ?"
        )->execute([
            $cantidadEntregada,
            $cantidadReservada,
            $idBodega,
            $idProducto,
            $idUnidad,
        ]);
    }

    // =========================================================================
    // REVERSAS (eliminación de lotes de alta)
    // =========================================================================

    /**
     * Revierte el stock al eliminar un lote de alta.
     * Descuenta de cantidad_total únicamente (los lotes de alta no reservan).
     *
     * @param int   $idBodega
     * @param int   $idProducto
     * @param int   $idUnidad
     * @param float $cantidad
     */
    public function revertirPorEliminacionLote(
        int $idBodega, int $idProducto, int $idUnidad, float $cantidad
    ): void {
        $this->connect->prepare(
            "UPDATE bodega_inventario.stock
             SET cantidad_total = GREATEST(0, cantidad_total - ?),
                 updated_at     = NOW()
             WHERE id_bodega = ? AND id_producto = ? AND id_unidad = ?"
        )->execute([$cantidad, $idBodega, $idProducto, $idUnidad]);
    }

    // =========================================================================
    // CONSULTAS DE STOCK
    // =========================================================================

    /**
     * Obtiene la fila de stock para un producto/unidad/bodega con bloqueo
     * FOR UPDATE (usar dentro de transacción).
     *
     * @return array|null  Fila de stock o null si no existe
     */
    public function obtenerConBloqueo(
        int $idBodega, int $idProducto, int $idUnidad
    ): ?array {
        $stmt = $this->connect->prepare(
            "SELECT id, cantidad_disponible, cantidad_reservada
             FROM   bodega_inventario.stock
             WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?
             FOR UPDATE"
        );
        $stmt->execute([$idBodega, $idProducto, $idUnidad]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Verifica si un producto/unidad tiene stock activo en una bodega.
     * Usado en validaciones de toggleUnidad y toggleProducto.
     *
     * @return float  Cantidad total en stock (0 si no hay registro)
     */
    public function obtenerCantidadTotal(
        int $idBodega, int $idProducto, int $idUnidad
    ): float {
        $stmt = $this->connect->prepare(
            "SELECT COALESCE(SUM(cantidad_total), 0)
             FROM   bodega_inventario.stock
             WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?"
        );
        $stmt->execute([$idBodega, $idProducto, $idUnidad]);

        return (float)$stmt->fetchColumn();
    }

    /**
     * Restaura cantidad_total al revertir una entrega.
     * No toca cantidad_reservada (cantidad_disponible es columna generada).
     */
    public function restaurarPorReversa(
        int $idBodega, int $idProducto, int $idUnidad, float $cantidad
    ): void {
        $this->connect->prepare(
            "UPDATE bodega_inventario.stock
         SET    cantidad_total = cantidad_total + ?,
                updated_at      = NOW()
         WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?"
        )->execute([$cantidad, $idBodega, $idProducto, $idUnidad]);
    }
}