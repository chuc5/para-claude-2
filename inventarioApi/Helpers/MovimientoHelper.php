<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * MovimientoHelper
 *
 * Centraliza el registro de movimientos en movimientos_stock.
 * La tabla es SOLO INSERT (auditoría inmutable): nunca UPDATE ni DELETE.
 *
 * Tipos de movimiento relevantes:
 *   1  → Alta por compra    (TM_ALTA_COMPRA)
 *   5  → Baja por entrega
 *   6  → Reversa de alta
 *   9  → Reserva
 *   10 → Liberación de reserva
 *
 * Dependencias: ninguna (helper base).
 */
class MovimientoHelper
{
    private PDO    $connect;
    private string $idUsuario;

    /**
     * @param PDO    $connect   Conexión PDO compartida con la clase principal
     * @param string $idUsuario ID del usuario en sesión (autor del movimiento)
     */
    public function __construct(PDO $connect, string $idUsuario)
    {
        $this->connect   = $connect;
        $this->idUsuario = $idUsuario;
    }

    // =========================================================================
    // REGISTRO GENÉRICO
    // =========================================================================

    /**
     * Inserta un registro en movimientos_stock.
     *
     * Debe ejecutarse dentro de una transacción abierta por la clase principal.
     * Este helper nunca hace commit ni rollback.
     *
     * @param int         $tipo              ID en tipos_movimiento
     * @param int         $idBodega
     * @param int         $idProducto
     * @param int         $idUnidad
     * @param float       $cantidad
     * @param string      $entidadOrigen     Nombre de la tabla que origina el movimiento
     * @param int         $idEntidadOrigen   ID del registro en la tabla origen
     * @param string|null $idReceptor        ID del usuario receptor (solo entregas)
     * @param int|null    $corrInicial       Correlativo inicial (solo tipo correlativo)
     * @param int|null    $corrFinal         Correlativo final   (solo tipo correlativo)
     */
    public function registrar(
        int     $tipo,
        int     $idBodega,
        int     $idProducto,
        int     $idUnidad,
        float   $cantidad,
        string  $entidadOrigen,
        int     $idEntidadOrigen,
        ?string $idReceptor    = null,
        ?int    $corrInicial   = null,
        ?int    $corrFinal     = null,
        ?float  $precioUnitario = null
    ): void {
        $sql = "INSERT INTO bodega_inventario.movimientos_stock
                    (id_bodega, id_producto, id_unidad, id_tipo_movimiento,
                     cantidad, precio_unitario, entidad_origen, id_entidad_origen,
                     correlativo_inicial, correlativo_final,
                     id_usuario, id_usuario_receptor)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->connect->prepare($sql);
        $stmt->execute([
            $idBodega, $idProducto, $idUnidad, $tipo,
            $cantidad, $precioUnitario, $entidadOrigen, $idEntidadOrigen,
            $corrInicial, $corrFinal, $this->idUsuario, $idReceptor,
        ]);
    }

    // =========================================================================
    // ATAJOS POR TIPO (mejoran legibilidad en los endpoints)
    // =========================================================================

    /**
     * Movimiento tipo 9: Reserva al crear solicitud.
     */
    public function registrarReserva(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidad, int $idDetalle
    ): void {
        $this->registrar(9, $idBodega, $idProducto, $idUnidad,
            $cantidad, 'solicitudes_detalle', $idDetalle);
    }

    /**
     * Movimiento tipo 10: Liberación de reserva (cancelación o rechazo).
     */
    public function registrarLiberacion(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidad, int $idDetalle
    ): void {
        $this->registrar(10, $idBodega, $idProducto, $idUnidad,
            $cantidad, 'solicitudes_detalle', $idDetalle);
    }

    /**
     * Movimiento tipo 5: Baja por entrega (lotes normal/expiración).
     */
    public function registrarBajaEntrega(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidad, int $idDetalle, ?string $idReceptor,
        string $tablaLotes, int $idLote
    ): void {
        $this->registrar(5, $idBodega, $idProducto, $idUnidad,
            $cantidad, 'solicitudes_detalle', $idDetalle,
            $idReceptor);
        // Nota: el registro de trazabilidad en solicitudes_detalle_lotes
        // lo maneja LotesHelper junto a este movimiento.
    }

    /**
     * Movimiento tipo 5 (o 7 para entrega directa) con correlativo.
     *
     * @param int $tipo  5 = Baja por entrega | 7 = Baja por entrega directa
     */
    public function registrarBajaEntregaCorrelativo(
        int $idBodega, int $idProducto,
        float $cantidad, int $idDetalle, ?string $idReceptor,
        int $corrInicial, int $corrFinal,
        int $tipo = 5
    ): void {
        $this->registrar($tipo, $idBodega, $idProducto, 1,
            $cantidad, 'solicitudes_detalle', $idDetalle,
            $idReceptor, $corrInicial, $corrFinal);
    }

    /**
     * Movimiento tipo 1 (TM_ALTA_COMPRA): Alta por ingreso de lote.
     *
     * @param string $tablaLote  'lotes_correlativo' | 'lotes_expiracion' | 'lotes_normal'
     * @param int    $idLote     ID del lote recién creado
     */
    public function registrarAltaCompra(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidad, string $tablaLote, int $idLote, ?float $precioUnitario = null
    ): void {
        $this->registrar(
            defined('TM_ALTA_COMPRA') ? TM_ALTA_COMPRA : 1,
            $idBodega, $idProducto, $idUnidad,
            $cantidad, $tablaLote, $idLote,
            null, null, null, $precioUnitario
        );
    }

    /**
     * Movimiento tipo 6: Reversa de alta al eliminar un lote.
     */
    public function registrarReversaAlta(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidad, string $tablaLote, int $idLote, ?float $precioUnitario = null
    ): void {
        $this->registrar(11, $idBodega, $idProducto, $idUnidad,
            $cantidad, $tablaLote, $idLote, null, null, null, $precioUnitario);
    }

    /**
     * Movimiento tipo 3: Alta por reversa de entrega.
     * Devuelve mercancía al stock al revertir una entrega ya despachada.
     */
    public function registrarReversaEntrega(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidad, int $idDetalle,
        ?int $corrInicial = null, ?int $corrFinal = null
    ): void {
        $this->registrar(3, $idBodega, $idProducto, $idUnidad,
            $cantidad, 'solicitudes_detalle', $idDetalle,
            null, $corrInicial, $corrFinal);
    }

    /**
     * Movimiento tipo 6: Baja por traslado (sale de la bodega origen).
     */
    public function registrarBajaTraslado(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidad, int $idDetalle,
        ?int $corrInicial = null, ?int $corrFinal = null, ?float $precioUnitario = null
    ): void {
        $this->registrar(6, $idBodega, $idProducto, $idUnidad,
            $cantidad, 'traslados_detalle', $idDetalle,
            null, $corrInicial, $corrFinal, $precioUnitario);
    }

    /**
     * Movimiento tipo 2: Alta por traslado (entra a la bodega destino).
     *
     * @param string $tablaLote  'lotes_correlativo' | 'lotes_expiracion' | 'lotes_normal'
     * @param int    $idLote     ID del lote recién creado en destino
     */
    public function registrarAltaTraslado(
        int $idBodega, int $idProducto, int $idUnidad,
        float $cantidad, string $tablaLote, int $idLote,
        ?int $corrInicial = null, ?int $corrFinal = null, ?float $precioUnitario = null
    ): void {
        $this->registrar(2, $idBodega, $idProducto, $idUnidad,
            $cantidad, $tablaLote, $idLote,
            null, $corrInicial, $corrFinal, $precioUnitario);
    }
}