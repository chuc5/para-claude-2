<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * SolicitudNotificacionHelper
 *
 * Traduce los eventos críticos del ciclo de vida de una solicitud de bodega a
 * notificaciones concretas: resuelve a quién avisar y con qué texto, para que
 * los endpoints se limiten a una línea.
 *
 * Ciclo (estados_solicitud):
 *   1 Reservada -> 2 Entregada -> 5 Revertida
 *   1 Reservada -> 3 Rechazada
 *   1 Reservada -> 4 Cancelada
 *
 * Quién recibe qué:
 *   Creada      -> encargados de la bodega (tienen que actuar)
 *   Entregada   -> solicitante
 *   Rechazada   -> solicitante
 *   Cancelada   -> la contraparte (ver notificarCancelada)
 *   Revertida   -> solicitante
 *   Entrega directa -> receptor
 *
 * Igual que NotificacionHelper, ningún método lanza excepciones: un fallo al
 * notificar no debe tumbar la operación de inventario.
 *
 * Dependencias: NotificacionHelper.
 */
class SolicitudNotificacionHelper
{
    public const ESTADO_RESERVADA = 1;
    public const ESTADO_ENTREGADA = 2;
    public const ESTADO_RECHAZADA = 3;
    public const ESTADO_CANCELADA = 4;
    public const ESTADO_REVERTIDA = 5;

    /** tipos_bodega: 1 = agencia, 2 = área */
    private const TIPO_BODEGA_AREA = 2;

    private PDO                $connect;
    private string             $idUsuario;
    private NotificacionHelper $notificacion;

    public function __construct(PDO $connect, string $idUsuario, NotificacionHelper $notificacion)
    {
        $this->connect      = $connect;
        $this->idUsuario    = $idUsuario;
        $this->notificacion = $notificacion;
    }

    // =========================================================================
    // EVENTOS
    // =========================================================================

    /** Solicitud nueva: avisa a los encargados de la bodega que deben atenderla */
    public function notificarCreada(int $idSolicitud): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $this->notificacion->enviarVarios(
            $this->_encargadosDeBodega((int)$s['id_bodega']),
            "Nueva solicitud #{$idSolicitud} en {$s['bodega']} pendiente de atender"
        );
    }

    /** Entrega despachada: avisa al solicitante */
    public function notificarEntregada(int $idSolicitud): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $this->notificacion->enviar(
            $s['id_usuario'],
            "Tu solicitud #{$idSolicitud} de {$s['bodega']} fue entregada"
        );
    }

    /** Rechazo: avisa al solicitante e incluye el motivo si lo hay */
    public function notificarRechazada(int $idSolicitud, ?string $motivo = null): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $texto = "Tu solicitud #{$idSolicitud} de {$s['bodega']} fue rechazada";
        if ($motivo !== null && trim($motivo) !== '') {
            $texto .= ': ' . trim($motivo);
        }

        $this->notificacion->enviar($s['id_usuario'], $texto);
    }

    /**
     * Cancelación: el destinatario depende de quién cancela.
     *  - Si cancela el solicitante -> avisa a los encargados (dejan de reservar stock)
     *  - Si cancela un encargado   -> avisa al solicitante (le deshicieron su pedido)
     */
    public function notificarCancelada(int $idSolicitud): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $esElSolicitante = ($s['id_usuario'] === $this->idUsuario);

        if ($esElSolicitante) {
            $this->notificacion->enviarVarios(
                $this->_encargadosDeBodega((int)$s['id_bodega']),
                "La solicitud #{$idSolicitud} de {$s['bodega']} fue cancelada por el solicitante"
            );
            return;
        }

        $this->notificacion->enviar(
            $s['id_usuario'],
            "Tu solicitud #{$idSolicitud} de {$s['bodega']} fue cancelada"
        );
    }

    /** Reversa de una entrega ya despachada: avisa al solicitante */
    public function notificarRevertida(int $idSolicitud): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $this->notificacion->enviar(
            $s['id_usuario'],
            "Se revirtió la entrega de tu solicitud #{$idSolicitud} de {$s['bodega']}"
        );
    }

    /**
     * Entrega directa: avisa al receptor que se le cargó un consumo sin que
     * él lo pidiera. En estas solicitudes `id_usuario` ya es el receptor.
     */
    public function notificarEntregaDirecta(int $idSolicitud, float $cantidad): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $cant = rtrim(rtrim(number_format($cantidad, 2, '.', ''), '0'), '.');

        $this->notificacion->enviar(
            $s['id_usuario'],
            "Se registró una entrega directa a tu nombre en {$s['bodega']} ({$cant} unidad/es)"
        );
    }

    // =========================================================================
    // PRIVADOS
    // =========================================================================

    /** Cabecera + nombre y tipo de la bodega, en una sola consulta */
    private function _solicitud(int $idSolicitud): ?array
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT s.id, s.id_usuario, s.id_bodega, s.id_estado, s.es_entrega_directa,
                        b.nombre AS bodega, b.id_tipo
                 FROM   bodega_inventario.solicitudes s
                 INNER JOIN bodega_inventario.bodegas b ON b.id = s.id_bodega
                 WHERE  s.id = ?
                 LIMIT  1"
            );
            $stmt->execute([$idSolicitud]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (Exception $e) {
            error_log("[SolicitudNotificacionHelper] _solicitud({$idSolicitud}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Encargados activos de una bodega.
     *
     * ⚠️ Solo existe tabla de encargados para bodegas de ÁREA
     * (inv_encargados_bodega_area). Para bodegas de agencia no hay equivalente,
     * así que devuelve vacío y nadie recibe el aviso de "solicitud creada".
     * Cuando definas cómo se identifica al encargado de agencia, agrégalo aquí
     * y todos los eventos lo toman automáticamente.
     *
     * @return string[]
     */
    private function _encargadosDeBodega(int $idBodega): array
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT e.id_usuario
                 FROM   bodega_inventario.inv_encargados_bodega_area e
                 INNER JOIN bodega_inventario.bodegas b ON b.id = e.id_bodega
                 WHERE  e.id_bodega = ? AND e.activo = 1 AND b.id_tipo = ?"
            );
            $stmt->execute([$idBodega, self::TIPO_BODEGA_AREA]);
            $encargados = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!$encargados) {
                error_log("[SolicitudNotificacionHelper] Bodega {$idBodega} sin encargados activos; nadie fue notificado");
            }

            return $encargados ?: [];
        } catch (Exception $e) {
            error_log("[SolicitudNotificacionHelper] _encargadosDeBodega({$idBodega}): " . $e->getMessage());
            return [];
        }
    }
}