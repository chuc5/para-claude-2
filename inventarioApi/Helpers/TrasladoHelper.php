<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;
use RuntimeException;

/**
 * TrasladoHelper
 *
 * Lógica interna del módulo de Traslados entre bodegas de agencia/área.
 * Centraliza:
 *   - Bloqueo y reserva de lotes específicos (correlativo/expiración) al crear el traslado.
 *   - Liberación de esas reservas al cancelar/rechazar.
 *   - Consumo físico definitivo y creación del lote espejo en destino al confirmar recepción.
 *
 * Para productos tipo Normal (FIFO) no se reserva un lote específico al crear: la
 * resolución (posiblemente multi-lote) ocurre hasta confirmarRecepcion, igual que
 * en las entregas de solicitudes.
 *
 * Todos los métodos que escriben en BD deben ejecutarse dentro de una transacción
 * abierta por la clase principal. Este helper nunca hace commit ni rollback.
 *
 * Dependencias: MovimientoHelper, StockHelper.
 */
class TrasladoHelper
{
    private PDO              $connect;
    private string            $idUsuario;
    private MovimientoHelper  $movimientoHelper;
    private StockHelper       $stockHelper;

    public function __construct(
        PDO $connect,
        string $idUsuario,
        MovimientoHelper $movimientoHelper,
        StockHelper $stockHelper
    ) {
        $this->connect          = $connect;
        $this->idUsuario        = $idUsuario;
        $this->movimientoHelper = $movimientoHelper;
        $this->stockHelper      = $stockHelper;
    }

    // =========================================================================
    // BODEGAS
    // =========================================================================

    /**
     * Obtiene una bodega activa junto con su bandera de autorización de traslados.
     *
     * @return object|null  {id, nombre, id_tipo, activo, requiere_autorizacion_traslado}
     */
    public function obtenerBodegaActiva(int $idBodega): ?object
    {
        $stmt = $this->connect->prepare(
            "SELECT id, nombre, id_tipo, activo, requiere_autorizacion_traslado
             FROM   bodega_inventario.bodegas
             WHERE  id = ? AND activo = 1
             LIMIT  1"
        );
        $stmt->execute([$idBodega]);
        $bodega = $stmt->fetch(PDO::FETCH_OBJ);

        if (!$bodega) {
            return null;
        }

        $bodega->id                             = (int)$bodega->id;
        $bodega->id_tipo                        = (int)$bodega->id_tipo;
        $bodega->activo                         = (int)$bodega->activo;
        $bodega->requiere_autorizacion_traslado = (bool)$bodega->requiere_autorizacion_traslado;

        return $bodega;
    }

    /**
     * Vuelve a validar (con bloqueo) que la bodega destino siga activa justo
     * antes de confirmar la recepción. Cubre el caso borde de desactivación
     * entre la creación/aprobación y la recepción.
     */
    public function bodegaDestinoSigueActiva(int $idBodega): bool
    {
        $stmt = $this->connect->prepare(
            "SELECT activo FROM bodega_inventario.bodegas WHERE id = ? FOR UPDATE"
        );
        $stmt->execute([$idBodega]);
        $activo = $stmt->fetchColumn();

        return $activo !== false && (bool)$activo;
    }

    // =========================================================================
    // RESERVA DE LOTE ESPECÍFICO AL CREAR (correlativo / expiración)
    // =========================================================================

    /**
     * Bloquea y valida un lote correlativo elegido por el encargado al crear el traslado.
     * "Libre" = cantidad_disponible - cantidad_reservada (lo que no está ya comprometido
     * por otro traslado en tránsito).
     *
     * @return array  Fila del lote (assoc) si es válido
     * @throws RuntimeException si no existe, no pertenece a la bodega/producto, o no alcanza
     */
    public function bloquearYReservarLoteCorrelativo(
        int $idLote, int $idBodegaOrigen, int $idProducto, int $cantidad
    ): array {
        $stmt = $this->connect->prepare(
            "SELECT id, cantidad_disponible, cantidad_reservada, precio_unitario
             FROM   bodega_inventario.lotes_correlativo
             WHERE  id = ? AND id_bodega = ? AND id_producto = ?
             FOR UPDATE"
        );
        $stmt->execute([$idLote, $idBodegaOrigen, $idProducto]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            throw new RuntimeException('El lote correlativo seleccionado no existe o no pertenece a esta bodega/producto');
        }

        $libre = (int)$lote['cantidad_disponible'] - (int)$lote['cantidad_reservada'];

        if ($libre < $cantidad) {
            throw new RuntimeException("El lote correlativo no tiene disponibilidad libre suficiente (libre: {$libre}, solicitado: {$cantidad})");
        }

        $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_correlativo
             SET    cantidad_reservada = cantidad_reservada + ?
             WHERE  id = ?"
        )->execute([$cantidad, $idLote]);

        return $lote;
    }

    /**
     * Bloquea y valida un lote de expiración elegido por el encargado al crear el traslado.
     *
     * @throws RuntimeException si no existe, no pertenece a la bodega/producto/unidad, o no alcanza
     */
    public function bloquearYReservarLoteExpiracion(
        int $idLote, int $idBodegaOrigen, int $idProducto, int $idUnidad, float $cantidad
    ): array {
        $stmt = $this->connect->prepare(
            "SELECT id, cantidad_disponible, cantidad_reservada, precio_unitario
             FROM   bodega_inventario.lotes_expiracion
             WHERE  id = ? AND id_bodega = ? AND id_producto = ? AND id_unidad = ?
             FOR UPDATE"
        );
        $stmt->execute([$idLote, $idBodegaOrigen, $idProducto, $idUnidad]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            throw new RuntimeException('El lote de expiración seleccionado no existe o no pertenece a esta bodega/producto/unidad');
        }

        $libre = (float)$lote['cantidad_disponible'] - (float)$lote['cantidad_reservada'];

        if ($libre < $cantidad) {
            throw new RuntimeException("El lote no tiene disponibilidad libre suficiente (libre: {$libre}, solicitado: {$cantidad})");
        }

        $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_expiracion
             SET    cantidad_reservada = cantidad_reservada + ?
             WHERE  id = ?"
        )->execute([$cantidad, $idLote]);

        return $lote;
    }

    /**
     * Libera la reserva de un lote correlativo (cancelación o rechazo del traslado).
     */
    public function liberarLoteCorrelativo(int $idLote, int $cantidad): void
    {
        $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_correlativo
             SET    cantidad_reservada = GREATEST(0, cantidad_reservada - ?)
             WHERE  id = ?"
        )->execute([$cantidad, $idLote]);
    }

    /**
     * Libera la reserva de un lote de expiración (cancelación o rechazo del traslado).
     */
    public function liberarLoteExpiracion(int $idLote, float $cantidad): void
    {
        $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_expiracion
             SET    cantidad_reservada = GREATEST(0, cantidad_reservada - ?)
             WHERE  id = ?"
        )->execute([$cantidad, $idLote]);
    }

    // =========================================================================
    // CONSUMO DEFINITIVO Y CREACIÓN DEL LOTE ESPEJO EN DESTINO (confirmarRecepcion)
    // =========================================================================


    /**
     * Ejecuta la baja definitiva del lote correlativo origen y crea el lote
     * espejo en destino, heredando serie/resolución/precio. El rango de
     * correlativos asignado se calcula aquí mismo desde el puntero del lote origen.
     *
     * @return array {id_lote_destino, correlativo_inicial, correlativo_final, precio_unitario}
     * @throws RuntimeException si el lote ya no tiene disponibilidad física suficiente
     */
    public function consumirYCrearDestinoCorrelativo(
        int $idLoteOrigen, int $cantidad, int $idBodegaDestino, ?string $idUsuarioEncargadoDestino, int $idTraslado
    ): array {
        $stmt = $this->connect->prepare(
            "SELECT id, id_producto, serie, resolucion, fecha_resolucion,
                    correlativo_siguiente, correlativo_final,
                    cantidad_disponible, cantidad_reservada, precio_unitario
             FROM   bodega_inventario.lotes_correlativo
             WHERE  id = ?
             FOR UPDATE"
        );
        $stmt->execute([$idLoteOrigen]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote || (int)$lote['cantidad_disponible'] < $cantidad) {
            throw new RuntimeException('El lote correlativo origen ya no cuenta con disponibilidad física suficiente para completar el traslado');
        }

        $corrIni = (int)$lote['correlativo_siguiente'];
        $corrFin = $corrIni + $cantidad - 1;

        // Baja definitiva en origen: descuenta disponible, libera la reserva, avanza el puntero
        $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_correlativo
             SET    cantidad_disponible   = cantidad_disponible   - ?,
                    cantidad_reservada    = GREATEST(0, cantidad_reservada - ?),
                    correlativo_siguiente = correlativo_siguiente + ?
             WHERE  id = ?"
        )->execute([$cantidad, $cantidad, $cantidad, $idLoteOrigen]);

        // Alta del lote espejo en destino, heredando datos regulatorios, precio y
        // dejando registrado el id_traslado como origen (en vez de id_alta)
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.lotes_correlativo
                 (id_bodega, id_producto, serie, resolucion, fecha_resolucion,
                  correlativo_inicial, correlativo_final, correlativo_siguiente,
                  cantidad_disponible, id_alta, id_traslado, id_usuario_encargado, precio_unitario)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)"
        )->execute([
            $idBodegaDestino, (int)$lote['id_producto'], $lote['serie'], $lote['resolucion'], $lote['fecha_resolucion'],
            $corrIni, $corrFin, $corrIni,
            $cantidad, $idTraslado, $idUsuarioEncargadoDestino, $lote['precio_unitario'],
        ]);

        return [
            'id_lote_destino'     => (int)$this->connect->lastInsertId(),
            'correlativo_inicial' => $corrIni,
            'correlativo_final'   => $corrFin,
            'precio_unitario'     => $lote['precio_unitario'] !== null ? (float)$lote['precio_unitario'] : null,
        ];
    }

    /**
     * Ejecuta la baja definitiva del lote de expiración origen y crea el lote
     * espejo en destino, heredando fecha_expiracion y precio.
     *
     * @return array {id_lote_destino, precio_unitario}
     * @throws RuntimeException si el lote ya no tiene disponibilidad física suficiente
     */
    public function consumirYCrearDestinoExpiracion(
        int $idLoteOrigen, float $cantidad, int $idBodegaDestino, int $idUnidad, ?string $idUsuarioEncargadoDestino, int $idTraslado
    ): array {
        $stmt = $this->connect->prepare(
            "SELECT id, id_producto, fecha_expiracion, cantidad_disponible, cantidad_reservada, precio_unitario
             FROM   bodega_inventario.lotes_expiracion
             WHERE  id = ?
             FOR UPDATE"
        );
        $stmt->execute([$idLoteOrigen]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote || (float)$lote['cantidad_disponible'] < $cantidad) {
            throw new RuntimeException('El lote de expiración origen ya no cuenta con disponibilidad física suficiente para completar el traslado');
        }

        $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_expiracion
             SET    cantidad_disponible = cantidad_disponible - ?,
                    cantidad_reservada  = GREATEST(0, cantidad_reservada - ?)
             WHERE  id = ?"
        )->execute([$cantidad, $cantidad, $idLoteOrigen]);

        $this->connect->prepare(
            "INSERT INTO bodega_inventario.lotes_expiracion
                 (id_bodega, id_producto, id_unidad, fecha_expiracion,
                  cantidad_disponible, id_alta, id_traslado, id_usuario_encargado, precio_unitario)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)"
        )->execute([
            $idBodegaDestino, (int)$lote['id_producto'], $idUnidad, $lote['fecha_expiracion'],
            $cantidad, $idTraslado, $idUsuarioEncargadoDestino, $lote['precio_unitario'],
        ]);

        return [
            'id_lote_destino' => (int)$this->connect->lastInsertId(),
            'precio_unitario' => $lote['precio_unitario'] !== null ? (float)$lote['precio_unitario'] : null,
        ];
    }

    /**
     * Resuelve un traslado de producto Normal aplicando FIFO sobre lotes_normal
     * de la bodega origen (puede cruzar varios lotes), creando un lote espejo en
     * destino por cada lote de origen consumido (fecha_ingreso = NOW(), ya que
     * físicamente entra hoy a la bodega destino).
     *
     * @return array  Lista de consumos: [{id_lote_origen, id_lote_destino, cantidad, precio_unitario}, ...]
     * @throws RuntimeException si el total disponible ya no alcanza la cantidad pedida
     */
    public function consumirYCrearDestinoNormal(
        int $idBodegaOrigen, int $idProducto, int $idUnidad, float $cantidad,
        int $idBodegaDestino, ?string $idUsuarioEncargadoDestino, int $idTraslado
    ): array {
        $stmt = $this->connect->prepare(
            "SELECT id, cantidad_disponible, precio_unitario
             FROM   bodega_inventario.lotes_normal
             WHERE  id_bodega   = ?
               AND  id_producto = ?
               AND  id_unidad   = ?
               AND  cantidad_disponible > 0
             ORDER  BY fecha_ingreso ASC
             FOR UPDATE"
        );
        $stmt->execute([$idBodegaOrigen, $idProducto, $idUnidad]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalDisponible = array_sum(array_column($lotes, 'cantidad_disponible'));
        if ($totalDisponible < $cantidad) {
            throw new RuntimeException("Stock insuficiente en el origen al momento de confirmar (disponible: {$totalDisponible}, requerido: {$cantidad})");
        }

        $pendiente = $cantidad;
        $consumos  = [];

        $stmtDescontar = $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_normal
             SET    cantidad_disponible = cantidad_disponible - ?
             WHERE  id = ?"
        );
        $stmtInsertDestino = $this->connect->prepare(
            "INSERT INTO bodega_inventario.lotes_normal
                 (id_bodega, id_producto, id_unidad, cantidad_disponible,
                  fecha_ingreso, id_alta, id_traslado, id_usuario_encargado, precio_unitario)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, NULL, ?, ?, ?)"
        );

        foreach ($lotes as $lote) {
            if ($pendiente <= 0) break;

            $consumir = min($pendiente, (float)$lote['cantidad_disponible']);

            $stmtDescontar->execute([$consumir, $lote['id']]);

            $stmtInsertDestino->execute([
                $idBodegaDestino, $idProducto, $idUnidad, $consumir,
                $idTraslado, $idUsuarioEncargadoDestino, $lote['precio_unitario'],
            ]);

            $consumos[] = [
                'id_lote_origen'  => (int)$lote['id'],
                'id_lote_destino' => (int)$this->connect->lastInsertId(),
                'cantidad'        => $consumir,
                'precio_unitario' => $lote['precio_unitario'] !== null ? (float)$lote['precio_unitario'] : null,
            ];

            $pendiente -= $consumir;
        }

        return $consumos;
    }

    // =========================================================================
    // TRAZABILIDAD
    // =========================================================================

    /**
     * Inserta una fila de trazabilidad origen→destino en traslados_detalle_lotes.
     */
    public function insertarDetalleLote(
        int $idTrasladoDetalle, float $cantidad,
        ?int $idLoteCorrOrigen = null, ?int $idLoteExpOrigen = null, ?int $idLoteNormalOrigen = null,
        ?int $idLoteCorrDestino = null, ?int $idLoteExpDestino = null, ?int $idLoteNormalDestino = null
    ): void {
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.traslados_detalle_lotes
                 (id_traslado_detalle, cantidad,
                  id_lote_corr_origen, id_lote_exp_origen, id_lote_normal_origen,
                  id_lote_corr_destino, id_lote_exp_destino, id_lote_normal_destino)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $idTrasladoDetalle, $cantidad,
            $idLoteCorrOrigen, $idLoteExpOrigen, $idLoteNormalOrigen,
            $idLoteCorrDestino, $idLoteExpDestino, $idLoteNormalDestino,
        ]);
    }
}