<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * BodegaHelper
 *
 * Centraliza toda la lógica de resolución de bodegas, verificación de
 * acceso por matriz y comprobaciones de unicidad de agencia/departamento.
 *
 * Dependencias: ninguna (helper base).
 */
class BodegaHelper
{
    private PDO $connect;
    private string $idUsuario;
    private int    $idAgencia;
    private ?int   $puesto;
    public const ID_AGENCIA_ADMINISTRACION = 99;

    /**
     * @param PDO    $connect   Conexión PDO compartida con la clase principal
     * @param string $idUsuario ID del usuario en sesión
     * @param int    $idAgencia ID de la agencia en sesión
     * @param int|null $puesto  ID del puesto en sesión
     */
    public function __construct(PDO $connect, string $idUsuario, int $idAgencia, ?int $puesto)
    {
        $this->connect   = $connect;
        $this->idUsuario = $idUsuario;
        $this->idAgencia = $idAgencia;
        $this->puesto    = $puesto;
    }

    // =========================================================================
    // RESOLUCIÓN DE BODEGA DEL ENCARGADO
    // =========================================================================

    /**
     * Devuelve el ID de la bodega de área asignada al usuario autenticado.
     * Busca en inv_encargados_bodega_area (activo = 1).
     *
     * @return int|null  ID de la bodega o null si no es encargado de área
     */
    public function obtenerBodegaArea(): ?int
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT id_bodega
                 FROM   bodega_inventario.inv_encargados_bodega_area
                 WHERE  id_usuario = ? AND activo = 1
                 LIMIT 1"
            );
            $stmt->execute([$this->idUsuario]);
            $id = $stmt->fetchColumn();

            return $id !== false ? (int)$id : null;
        } catch (Exception $e) {
            error_log("[BodegaHelper] obtenerBodegaArea: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Devuelve el ID de la bodega de agencia correspondiente a la agencia
     * del usuario en sesión (id_tipo = 1, activo = 1).
     *
     * @return int|null  ID de la bodega o null si no existe
     */
    public function obtenerBodegaAgencia(): ?int
    {
        if (!$this->idAgencia) {
            return null;
        }

        try {
            $stmt = $this->connect->prepare(
                "SELECT id
                 FROM   bodega_inventario.bodegas
                 WHERE  id_tipo    = 1
                   AND  id_agencia = ?
                   AND  activo     = 1
                 LIMIT 1"
            );
            $stmt->execute([$this->idAgencia]);
            $id = $stmt->fetchColumn();

            return $id !== false ? (int)$id : null;
        } catch (Exception $e) {
            error_log("[BodegaHelper] obtenerBodegaAgencia: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resuelve la bodega del encargado con prioridad:
     *   1. Bodega de área  → inv_encargados_bodega_area
     *   2. Bodega de agencia → bodegas WHERE id_tipo=1 AND id_agencia=sesión
     *
     * Usado por obtenerBodegaEncargado y obtenerDetalleSolicitud.
     *
     * @return int|null  ID de la bodega o null si no es encargado de ninguna
     */
    public function obtenerBodegaEncargado(): ?int
    {
        $idArea = $this->obtenerBodegaArea();
        if ($idArea !== null) {
            return $idArea;
        }

        return $this->obtenerBodegaAgencia();
    }

    /**
     * Bodega de la agencia 99 — la que usa el Administrador de Bodegas para
     * registrar las compras de todas las agencias.
     *
     * A diferencia de obtenerBodegaAgencia(), NO depende de la agencia del
     * usuario en sesión: la agencia está fija. Quién puede usar este contexto
     * se valida en el endpoint, no aquí.
     */
    public function obtenerBodegaAdministracion(): ?int
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT id
             FROM   bodega_inventario.bodegas
             WHERE  id_tipo    = 1
               AND  id_agencia = ?
               AND  activo     = 1
             LIMIT 1"
            );
            $stmt->execute([self::ID_AGENCIA_ADMINISTRACION]);
            $id = $stmt->fetchColumn();

            return $id !== false ? (int)$id : null;
        } catch (Exception $e) {
            error_log("[BodegaHelper] obtenerBodegaAdministracion: " . $e->getMessage());
            return null;
        }
    }
    /**
     * Resuelve la bodega según el contexto explícito enviado por el frontend.
     *
     * @param string $contexto  'area' | 'agencia' | 'administracion'
     * @return int|null
     */
    public function obtenerBodegaPorContexto(string $contexto): ?int
    {
        if ($contexto === 'agencia') {
            return $this->obtenerBodegaAgencia();
        }
        if ($contexto === 'administracion') {
            return $this->obtenerBodegaAdministracion();
        }
        return $this->obtenerBodegaArea();
    }


    /**
     * Devuelve datos básicos de una bodega necesarios para validar operaciones
     * (tipo y si tiene habilitada la Entrega Directa).
     *
     * @param int $idBodega
     * @return object|null  {id, id_tipo, permite_entrega_directa} o null si no existe/inactiva
     */
    public function obtenerInfoBodega(int $idBodega): ?object
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT id, id_tipo, permite_entrega_directa
             FROM   bodega_inventario.bodegas
             WHERE  id = ? AND activo = 1
             LIMIT 1"
            );
            $stmt->execute([$idBodega]);
            $row = $stmt->fetch(PDO::FETCH_OBJ);

            return $row ?: null;
        } catch (Exception $e) {
            error_log("[BodegaHelper] obtenerInfoBodega: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Devuelve el ID de la bodega de área del encargado validando también
     * que la bodega esté activa y sea de tipo 2.
     * Usado por los módulos Mi Bodega y Mi Matriz.
     *
     * @return int|null
     */
    public function obtenerBodegaDelEncargado(): ?int
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT e.id_bodega
                 FROM bodega_inventario.inv_encargados_bodega_area e
                 INNER JOIN bodega_inventario.bodegas b ON b.id = e.id_bodega
                 WHERE e.id_usuario = ?
                   AND e.activo    = 1
                   AND b.activo    = 1
                   AND b.id_tipo   = 2
                 LIMIT 1"
            );
            $stmt->execute([$this->idUsuario]);
            $id = $stmt->fetchColumn();

            return $id !== false ? (int)$id : null;
        } catch (Exception $e) {
            error_log("[BodegaHelper] obtenerBodegaDelEncargado: " . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // VERIFICACIÓN DE ACCESO POR MATRIZ
    // =========================================================================

    /**
     * Verifica si el usuario tiene acceso activo a una bodega de área
     * con restricción habilitada, consultando la matriz de acceso.
     *
     * Solo debe llamarse para bodegas de área con restriccion_acceso_activa = 1.
     *
     * @param int      $idBodega  ID de la bodega de área
     * @param int|null $idPuesto  Puesto del usuario (de sesión)
     * @param int      $idAgencia Agencia del usuario (de sesión)
     * @return bool
     */
    public function tieneAccesoMatriz(int $idBodega, ?int $idPuesto, int $idAgencia): bool
    {
        if (!$idPuesto) {
            return false;
        }

        try {
            $stmt = $this->connect->prepare(
                "SELECT COUNT(*)
                 FROM bodega_inventario.matriz_acceso
                 WHERE id_bodega  = ?
                   AND id_puesto  = ?
                   AND id_agencia = ?
                   AND activo     = 1"
            );
            $stmt->execute([$idBodega, $idPuesto, $idAgencia]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log("[BodegaHelper] tieneAccesoMatriz: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica el acceso usando los valores de sesión del helper
     * (atajo para no repetir $this->puesto / $this->idAgencia en cada llamada).
     *
     * @param int $idBodega
     * @return bool
     */
    public function tieneAccesoMatrizSesion(int $idBodega): bool
    {
        return $this->tieneAccesoMatriz($idBodega, $this->puesto, $this->idAgencia);
    }

    /**
     * Verifica que una celda (registro en matriz_acceso) pertenezca
     * a la bodega indicada. Previene manipulación de IDs ajenos.
     *
     * @param int $idMatriz  ID del registro en matriz_acceso
     * @param int $idBodega  ID de la bodega esperada
     * @return bool
     */
    public function celdaPertenece(int $idMatriz, int $idBodega): bool
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT id FROM bodega_inventario.matriz_acceso
                 WHERE id = ? AND id_bodega = ?"
            );
            $stmt->execute([$idMatriz, $idBodega]);

            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("[BodegaHelper] celdaPertenece: " . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // UNICIDAD DE AGENCIA / DEPARTAMENTO
    // =========================================================================

    /**
     * Verifica si una agencia ya tiene una bodega activa registrada.
     *
     * @param int $idAgencia   ID de la agencia
     * @param int $excluirId   ID de la bodega a excluir (0 en creación, id propio en edición)
     * @return bool
     */
    public function agenciaTieneBodegaActiva(int $idAgencia, int $excluirId): bool
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT id FROM bodega_inventario.bodegas
                 WHERE id_tipo    = 1
                   AND id_agencia = ?
                   AND activo     = 1
                   AND id        != ?"
            );
            $stmt->execute([$idAgencia, $excluirId]);

            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("[BodegaHelper] agenciaTieneBodegaActiva: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica si un departamento ya tiene una bodega activa registrada.
     *
     * @param int $idDepto    ID del departamento
     * @param int $excluirId  ID de la bodega a excluir (0 en creación, id propio en edición)
     * @return bool
     */
    public function departamentoTieneBodegaActiva(int $idDepto, int $excluirId): bool
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT id FROM bodega_inventario.bodegas
                 WHERE id_tipo                    = 2
                   AND id_departamento_cooperativa = ?
                   AND activo                     = 1
                   AND id                        != ?"
            );
            $stmt->execute([$idDepto, $excluirId]);

            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("[BodegaHelper] departamentoTieneBodegaActiva: " . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // ACCESORES DE SESIÓN (para evitar pasar parámetros repetidos)
    // =========================================================================

    /** Retorna el ID de agencia de sesión */
    public function getIdAgencia(): int
    {
        return $this->idAgencia;
    }

    /** Retorna el puesto de sesión */
    public function getPuesto(): ?int
    {
        return $this->puesto;
    }
}