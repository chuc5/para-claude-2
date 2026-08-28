<?php


namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * NotificacionHelper
 *
 * Inserta avisos en dbintranet.notificacion, la tabla que ya usa el resto de
 * la intranet. Solo se notifican gestiones críticas (aprobar, rechazar,
 * revertir, pagar), no cada movimiento del sistema: si todo notifica, nada
 * se lee.
 *
 * REGLA IMPORTANTE: este helper NUNCA lanza excepciones. Una notificación que
 * no se pudo guardar es un problema menor; una entrega de inventario revertida
 * porque falló el INSERT del aviso es un problema grave. Todo error se atrapa
 * y se manda a error_log.
 *
 * Sobre `estado`: 1 = visible para el usuario, 0 = registrada pero oculta.
 * Cuando quien ejecuta la acción es también el destinatario, la fila se guarda
 * con estado 0: queda el rastro en la tabla sin llenarle la bandeja de ecos de
 * sus propias acciones.
 *
 * Sobre transacciones: los métodos se pueden llamar dentro de la transacción
 * abierta por la clase principal. En InnoDB un INSERT fallido no invalida la
 * transacción en curso, así que el catch es seguro. La ventaja de estar dentro
 * es que si la operación hace rollback, la notificación desaparece con ella y
 * no se avisa de algo que nunca pasó.
 *
 * Dependencias: ninguna (helper base).
 */
class NotificacionHelper
{
    /**
     * ⚠️ PENDIENTE: FK a dbintranet.aplicaciones.idAplicacion.
     * Reemplaza por el id real del módulo de inventario/bodegas. Si queda mal,
     * cada INSERT fallará por constraint (silenciosamente, según la regla de
     * arriba, pero no llegará ninguna notificación).
     */
    private const ID_APLICACION = 28;

    /** Visible en la bandeja del usuario */
    public const ESTADO_VISIBLE = 1;

    /** Registrada para auditoría, pero no se le muestra al usuario */
    public const ESTADO_OCULTA = 0;

    private PDO $connect;
    private string $idUsuario;

    public function __construct(PDO $connect, string $idUsuario)
    {
        $this->connect = $connect;
        $this->idUsuario = $idUsuario;
    }

    // =========================================================================
    // API PÚBLICA
    // =========================================================================

    /**
     * Notifica a un usuario.
     *
     * Si el destinatario es quien ejecuta la acción, la fila se inserta igual
     * pero con estado 0 (oculta): se conserva el registro sin molestarlo.
     *
     * @param string|null $usuarioNotificado dbintranet.usuarios.idUsuarios
     * @param string $mensaje Texto que verá el usuario
     * @param int|null $idAplicacion Sobrescribe la app por defecto
     * @param int|null $forzarEstado Fuerza 0 u 1 ignorando la regla anterior
     */
    public function enviar(
        ?string $usuarioNotificado,
        string  $mensaje,
        ?int    $idAplicacion = null,
        ?int    $forzarEstado = null
    ): void
    {
        $destinatario = trim((string)$usuarioNotificado);
        $mensaje = trim($mensaje);

        if ($destinatario === '' || $mensaje === '') {
            return;
        }

        $estado = $forzarEstado ?? ($destinatario === $this->idUsuario
            ? self::ESTADO_OCULTA
            : self::ESTADO_VISIBLE);

        $this->_insertar($destinatario, $mensaje, $idAplicacion ?? self::ID_APLICACION, $estado);
    }

    /**
     * Notifica a varios usuarios el mismo mensaje. Deduplica la lista para no
     * mandar el aviso dos veces a quien aparezca repetido.
     *
     * @param array<string|null> $usuariosNotificados
     */
    public function enviarVarios(
        array  $usuariosNotificados,
        string $mensaje,
        ?int   $idAplicacion = null,
        ?int   $forzarEstado = null
    ): void
    {
        $destinatarios = array_unique(array_filter(array_map(
            static fn($u) => trim((string)$u),
            $usuariosNotificados
        )));

        foreach ($destinatarios as $destinatario) {
            $this->enviar($destinatario, $mensaje, $idAplicacion, $forzarEstado);
        }
    }

    // =========================================================================
    // PRIVADO
    // =========================================================================

    private function _insertar(string $usuarioNotificado, string $mensaje, int $idAplicacion, int $estado): void
    {
        try {
            $payload = json_encode(
                ['notificacion' => $mensaje],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $this->connect->prepare(
                "INSERT INTO dbintranet.notificacion
                     (usuarioNotificado, usuarioEnvio, idAplicacion, notificacion,
                      estado, fechaNotificacion)
                 VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
            )->execute([$usuarioNotificado, $this->idUsuario, $idAplicacion, $payload, $estado]);
        } catch (Exception $e) {
            // Nunca propagar: la notificación es secundaria a la operación
            error_log(
                "[NotificacionHelper] No se pudo notificar a {$usuarioNotificado}: " . $e->getMessage()
            );
        }
    }
}