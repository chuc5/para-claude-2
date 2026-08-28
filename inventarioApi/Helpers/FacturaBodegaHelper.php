<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * FacturaBodegaHelper
 *
 * Módulo de Facturas para encargados de bodega — vive enteramente en
 * inventarioApiClass/sus helpers. NO reutiliza los métodos de la clase de
 * Compras (buscarPorNumeroDte / insertarFacturaDesdeModal viven en otra
 * ruta) — aquí hay versiones propias, aunque escriban/lean la MISMA tabla
 * compras.facturas_sat.
 *
 * Flujo (estado_liquidacion de compras.facturas_sat, sin tabla de estados
 * nueva — se reutiliza el enum que ya trae):
 *   Pendiente -> [contabilidad] -> Correccion -> [encargado reenvía] -> Pendiente
 *   Pendiente -> [contabilidad verifica, opcional] -> Verificado
 *   Pendiente O Verificado -> [contabilidad sube comprobante] -> Pagado
 *   (el comprobante lo sube CONTABILIDAD, no el encargado; puede saltarse
 *   'Verificado' y subir el comprobante directo desde 'Pendiente' para no
 *   ser burocrático cuando ya tiene todo listo)
 *
 * Auditoría: CADA transición queda en solicitudes_factura_historial
 * (quién, cuándo, estado anterior/nuevo, motivo). solicitudes_factura solo
 * guarda el último evento de corrección para lectura rápida en pantalla.
 *
 * Los endpoints públicos son responsables de la validación de permisos
 * (el helper no valida roles — así se acordó).
 */
class FacturaBodegaHelper
{
    private PDO $connect;
    private string $idUsuario;

    public function __construct(PDO $connect, string $idUsuario)
    {
        $this->connect = $connect;
        $this->idUsuario = $idUsuario;
    }

    // =========================================================================
    // BÚSQUEDA / REGISTRO DE FACTURA SAT (tabla compras.facturas_sat)
    // Versión propia — no llama a la clase de Compras.
    // =========================================================================

    /**
     * Busca una factura por número de DTE. Devuelve null si no existe.
     * No incluye días hábiles (eso es propio del módulo de Compras/Presupuesto,
     * aquí no aplica).
     */
    public function buscarPorNumeroDte(string $numeroDte): ?array
    {
        $numeroDte = mb_strtoupper(trim($numeroDte), 'UTF-8');

        $stmt = $this->connect->prepare(
            "SELECT id, numero_dte, fecha_emision, numero_autorizacion, tipo_dte,
                    nombre_emisor, nombre_establecimiento, monto_total, moneda,
                    estado, estado_liquidacion, retencion, tiene_autorizacion_tardanza,
                    fecha_creacion, fecha_actualizacion
             FROM   compras.facturas_sat
             WHERE  numero_dte = ?
             LIMIT  1"
        );
        $stmt->execute([$numeroDte]);
        $factura = $stmt->fetch(PDO::FETCH_ASSOC);

        return $factura ?: null;
    }

    /**
     * Registra una factura SAT o un recibo (mismo storage, distinto tipo_dte).
     * Si el número de DTE ya existe y está en estado 'Pendiente', actualiza
     * en vez de duplicar (mismo criterio que la versión de Compras).
     *
     * @param object $datos { numero_dte, fecha_emision, numero_autorizacion,
     *                        tipo_dte, nombre_emisor, monto_total, moneda? }
     * @param object|null $archivo Detectado vía $_FILES (ver detectarArchivo())
     * @return array {id_factura, accion: 'insertado'|'actualizado', drive_id?: string}
     */
    public function registrarFactura(object $datos, ?object $archivo = null): array
    {
        $this->_validarDatosFactura($datos);

        $numeroDte = mb_strtoupper(trim($datos->numero_dte), 'UTF-8');
        $fechaEmision = trim($datos->fecha_emision);
        $nombreEmisor = mb_strtoupper(trim($datos->nombre_emisor), 'UTF-8');
        $montoTotal = (float)$datos->monto_total;
        $moneda = in_array($datos->moneda ?? 'GTQ', ['GTQ', 'USD'], true) ? $datos->moneda : 'GTQ';
        $tipoDte = trim($datos->tipo_dte);
        $numeroAutorizacion = trim($datos->numero_autorizacion);

        if ($archivo) {
            $this->_validarArchivo($archivo);
        }

        $stmt = $this->connect->prepare(
            "SELECT id FROM compras.facturas_sat
             WHERE  numero_dte = ? AND estado_liquidacion = 'Pendiente'
             LIMIT  1"
        );
        $stmt->execute([$numeroDte]);
        $idExistente = $stmt->fetchColumn();

        if ($idExistente) {
            $this->connect->prepare(
                "UPDATE compras.facturas_sat
                 SET    fecha_emision = ?, numero_autorizacion = ?, tipo_dte = ?,
                        nombre_emisor = ?, monto_total = ?, moneda = ?,
                        estado = 'vigente', fecha_actualizacion = NOW()
                 WHERE  id = ?"
            )->execute([$fechaEmision, $numeroAutorizacion, $tipoDte, $nombreEmisor, $montoTotal, $moneda, $idExistente]);

            $idFactura = (int)$idExistente;
            $accion = 'actualizado';
        } else {
            $this->connect->prepare(
                "INSERT INTO compras.facturas_sat
                     (numero_dte, fecha_emision, numero_autorizacion, tipo_dte,
                      nombre_emisor, monto_total, moneda, estado, estado_liquidacion,
                      fecha_creacion, fecha_actualizacion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'vigente', 'Pendiente', NOW(), NOW())"
            )->execute([$numeroDte, $fechaEmision, $numeroAutorizacion, $tipoDte, $nombreEmisor, $montoTotal, $moneda]);

            $idFactura = (int)$this->connect->lastInsertId();
            $accion = 'insertado';
        }

        $resultado = ['id_factura' => $idFactura, 'accion' => $accion];

        if ($archivo) {
            if ($this->_registrarArchivoFactura($archivo, $idFactura, $numeroDte)) {
                $resultado['archivo_subido'] = true;
            }
        }

        return $resultado;
    }

    private function _validarDatosFactura(object $datos): void
    {
        if (empty($datos->numero_dte) || trim($datos->numero_dte) === '') {
            throw new Exception('El número de DTE es requerido');
        }
        if (!preg_match('/^[A-Za-z0-9\-]+$/', trim($datos->numero_dte))) {
            throw new Exception('El número de DTE contiene caracteres no permitidos');
        }
        if (empty($datos->fecha_emision)) {
            throw new Exception('La fecha de emisión es requerida');
        }
        if (empty($datos->nombre_emisor)) {
            throw new Exception('El nombre del emisor es requerido');
        }
        if (!isset($datos->monto_total) || (float)$datos->monto_total <= 0) {
            throw new Exception('El monto total debe ser mayor a cero');
        }
        if (empty($datos->numero_autorizacion)) {
            throw new Exception('El número de autorización es requerido');
        }
        if (empty($datos->tipo_dte)) {
            throw new Exception('El tipo de DTE es requerido');
        }
    }

    private function _validarArchivo(object $archivo): void
    {
        $tiposPermitidos = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($archivo->type, $tiposPermitidos, true)) {
            throw new Exception('Solo se permiten archivos PDF, JPG, JPEG, PNG o WEBP');
        }
        if ($archivo->size > 5 * 1024 * 1024) {
            throw new Exception('El archivo no puede exceder 5MB');
        }
        $extension = strtolower(pathinfo($archivo->name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new Exception('Extensión no permitida. Use: PDF, JPG, JPEG, PNG o WEBP');
        }
    }

    /** Detecta un archivo en $_FILES, igual criterio que el módulo de Compras */
    public function detectarArchivo(array $nombresEsperados = ['archivo_factura', 'archivo']): ?object
    {
        if (empty($_FILES)) return null;

        foreach ($nombresEsperados as $nombre) {
            if (isset($_FILES[$nombre]) && $_FILES[$nombre]['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES[$nombre];
                return (object)['name' => $f['name'], 'tmp_name' => $f['tmp_name'], 'type' => $f['type'], 'size' => $f['size'], 'error' => $f['error']];
            }
        }
        foreach ($_FILES as $f) {
            if (($f['error'] ?? null) === UPLOAD_ERR_OK) {
                return (object)['name' => $f['name'], 'tmp_name' => $f['tmp_name'], 'type' => $f['type'], 'size' => $f['size'], 'error' => $f['error']];
            }
        }
        return null;
    }

    /** Sube a Drive en Archivos/Bodega_Inventario/{subcarpeta}/YYYY-MM/ */
    private function _subirArchivoDrive(object $archivo, int $idReferencia, string $etiqueta, string $subcarpeta): ?string
    {
        try {
            $drive = new \App\drive\EnvDriveClass();
            error_reporting(E_ALL ^ E_DEPRECATED);

            $idRaiz = $drive->getArchivosId();
            $extension = strtolower(pathinfo($archivo->name, PATHINFO_EXTENSION));
            $etiquetaLimpia = preg_replace('/[^A-Za-z0-9\-_]/', '_', $etiqueta);
            $nombreArchivo = "{$subcarpeta}_{$etiquetaLimpia}_{$idReferencia}_" . time() . ".{$extension}";

            $idCarpetaArchivos = $drive->verificaExisteCreaCarpeta('Archivos', $idRaiz);
            $idCarpetaBodega = $drive->verificaExisteCreaCarpeta('Bodega_Inventario', $idCarpetaArchivos);
            $idCarpetaTipo = $drive->verificaExisteCreaCarpeta($subcarpeta, $idCarpetaBodega);
            $idCarpetaFecha = $drive->verificaExisteCreaCarpeta(date('Y-m'), $idCarpetaTipo);

            $adaptado = new class($archivo, $nombreArchivo) {
                private object $archivo;
                private string $nombreDrive;

                public function __construct(object $archivo, string $nombreDrive)
                {
                    $this->archivo = $archivo;
                    $this->nombreDrive = $nombreDrive;
                }

                public function getClientFilename()
                {
                    return $this->nombreDrive;
                }

                public function getClientMediaType()
                {
                    return $this->archivo->type;
                }

                public function getSize()
                {
                    return $this->archivo->size;
                }

                public function getError()
                {
                    return $this->archivo->error;
                }

                public function moveTo($targetPath)
                {
                    return move_uploaded_file($this->archivo->tmp_name, $targetPath);
                }
            };

            $resultado = $drive->cargaADrive($adaptado, $nombreArchivo, $idCarpetaFecha);
            return $resultado['id'] ?? null;
        } catch (\Exception $e) {
            error_log('FacturaBodegaHelper::_subirArchivoDrive: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // SOLICITUDES (bodega_inventario.solicitudes_factura)
    // =========================================================================

    public function crearSolicitud(int $idFacturaSat, int $idBodega, string $detalle): int
    {
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.solicitudes_factura
                 (id_factura_sat, id_bodega, id_usuario_solicitante, detalle, created_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
        )->execute([$idFacturaSat, $idBodega, $this->idUsuario, $detalle]);

        $idSolicitud = (int)$this->connect->lastInsertId();

        $this->_registrarHistorial($idSolicitud, null, 'Pendiente', 'Solicitud creada y enviada a revisión');

        return $idSolicitud;
    }

    public function listarMisSolicitudes(int $idBodega, array $filtros = []): array
    {
        return $this->_listar(['sf.id_bodega = ?'], [$idBodega], $filtros);
    }

    /** Bandeja de contabilidad: todas las bodegas, con el mismo set de filtros */
    public function listarBandejaRevision(array $filtros = []): array
    {
        return $this->_listar([], [], $filtros);
    }

    private function _listar(array $condicionesBase, array $paramsBase, array $filtros): array
    {
        $condiciones = $condicionesBase;
        $params = $paramsBase;

        if (!empty($filtros['estado'])) {
            $condiciones[] = 'fs.estado_liquidacion = ?';
            $params[] = $filtros['estado'];
        }
        if (!empty($filtros['busqueda'])) {
            $condiciones[] = '(fs.numero_dte LIKE ? OR fs.nombre_emisor LIKE ?)';
            $params[] = "%{$filtros['busqueda']}%";
            $params[] = "%{$filtros['busqueda']}%";
        }

        $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

        $pagina = max(1, (int)($filtros['pagina'] ?? 1));
        $porPagina = (int)($filtros['por_pagina'] ?? 20);
        $porPagina = ($porPagina < 1 || $porPagina > 100) ? 20 : $porPagina;
        $offset = ($pagina - 1) * $porPagina;

        $sqlTotal = "SELECT COUNT(*)
                 FROM bodega_inventario.solicitudes_factura sf
                 INNER JOIN compras.facturas_sat fs ON fs.id = sf.id_factura_sat
                 INNER JOIN bodega_inventario.bodegas b ON b.id = sf.id_bodega
                 {$where}";
        $stmtTotal = $this->connect->prepare($sqlTotal);
        $stmtTotal->execute($params);
        $total = (int)$stmtTotal->fetchColumn();

        $sql = "SELECT
                sf.id, sf.id_factura_sat, sf.id_bodega, sf.id_usuario_solicitante,
                sf.detalle, sf.motivo_correccion, sf.id_usuario_revisor, sf.fecha_revision,
                sf.comprobante_drive_id, sf.comprobante_nombre, sf.fecha_comprobante,
                sf.created_at, sf.updated_at,
                fs.numero_dte, fs.tipo_dte, fs.fecha_emision, fs.nombre_emisor,
                fs.monto_total, fs.moneda, fs.estado_liquidacion,
                                b.nombre AS bodega,
                dps.nombres AS nombre_solicitante,
                dpr.nombres AS nombre_revisor,
                af.drive_id        AS archivo_drive_id,
                af.nombre_original AS archivo_nombre,
                af.tipo_mime       AS archivo_tipo_mime
            FROM bodega_inventario.solicitudes_factura sf
            INNER JOIN compras.facturas_sat fs ON fs.id = sf.id_factura_sat
            INNER JOIN bodega_inventario.bodegas b ON b.id = sf.id_bodega
            LEFT JOIN dbintranet.usuarios us ON us.idUsuarios = sf.id_usuario_solicitante
            LEFT JOIN dbintranet.datospersonales dps ON dps.idDatosPersonales = us.idDatosPersonales
            LEFT JOIN dbintranet.usuarios ur ON ur.idUsuarios = sf.id_usuario_revisor
            LEFT JOIN dbintranet.datospersonales dpr ON dpr.idDatosPersonales = ur.idDatosPersonales
            LEFT JOIN (
                SELECT a.id, a.id_factura, a.drive_id, a.nombre_original, a.tipo_mime
                FROM   compras.archivos_facturas_subidas a
                INNER JOIN (
                    SELECT id_factura, MAX(id) AS ultimo
                    FROM   compras.archivos_facturas_subidas
                    GROUP  BY id_factura
                ) u ON u.ultimo = a.id
            ) af ON af.id_factura = sf.id_factura_sat
            {$where}
            ORDER BY sf.created_at DESC
            LIMIT {$porPagina} OFFSET {$offset}";

        $stmt = $this->connect->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$it) {
            $it['id'] = (int)$it['id'];
            $it['id_factura_sat'] = (int)$it['id_factura_sat'];
            $it['id_bodega'] = (int)$it['id_bodega'];
            $it['monto_total'] = (float)$it['monto_total'];
        }
        unset($it);

        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    public function obtenerSolicitud(int $idSolicitud): ?array
    {
        $stmt = $this->connect->prepare(
            "SELECT sf.*, fs.numero_dte, fs.tipo_dte, fs.fecha_emision, fs.nombre_emisor,
                fs.monto_total, fs.moneda, fs.estado_liquidacion, b.nombre AS bodega,
                af.drive_id        AS archivo_drive_id,
                af.nombre_original AS archivo_nombre,
                af.tipo_mime       AS archivo_tipo_mime
         FROM   bodega_inventario.solicitudes_factura sf
         INNER JOIN compras.facturas_sat fs ON fs.id = sf.id_factura_sat
         INNER JOIN bodega_inventario.bodegas b ON b.id = sf.id_bodega
         LEFT JOIN (
             SELECT a.id, a.id_factura, a.drive_id, a.nombre_original, a.tipo_mime
             FROM   compras.archivos_facturas_subidas a
             INNER JOIN (
                 SELECT id_factura, MAX(id) AS ultimo
                 FROM   compras.archivos_facturas_subidas
                 GROUP  BY id_factura
             ) u ON u.ultimo = a.id
         ) af ON af.id_factura = sf.id_factura_sat
         WHERE  sf.id = ?"
        );
        $stmt->execute([$idSolicitud]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Historial completo de auditoría de una solicitud (todas las transiciones) */
    public function obtenerHistorial(int $idSolicitud): array
    {
        $stmt = $this->connect->prepare(
            "SELECT h.id, h.id_usuario, h.estado_anterior, h.estado_nuevo, h.motivo, h.created_at,
                    dp.nombres AS nombre_usuario
             FROM   bodega_inventario.solicitudes_factura_historial h
             LEFT JOIN dbintranet.usuarios u ON u.idUsuarios = h.id_usuario
             LEFT JOIN dbintranet.datospersonales dp ON dp.idDatosPersonales = u.idDatosPersonales
             WHERE  h.id_solicitud_factura = ?
             ORDER  BY h.created_at ASC"
        );
        $stmt->execute([$idSolicitud]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // TRANSICIONES DE ESTADO (todas dejan huella en el historial)
    // =========================================================================

    public function solicitarCorreccion(int $idSolicitud, string $motivo): void
    {
        $solicitud = $this->_solicitudOFail($idSolicitud);

        $this->connect->prepare(
            "UPDATE compras.facturas_sat
             SET    estado_liquidacion = 'Correcion', fecha_actualizacion = NOW()
             WHERE  id = ?"
        )->execute([$solicitud['id_factura_sat']]);

        $this->connect->prepare(
            "UPDATE bodega_inventario.solicitudes_factura
             SET    motivo_correccion = ?, id_usuario_revisor = ?, fecha_revision = NOW()
             WHERE  id = ?"
        )->execute([$motivo, $this->idUsuario, $idSolicitud]);

        $this->_registrarHistorial($idSolicitud, $solicitud['estado_liquidacion'], 'Correccion', $motivo);
    }

    /**
     * El encargado corrige el detalle (y opcionalmente reemplaza el archivo de la
     * factura) y reenvía — vuelve a 'Pendiente'.
     */
    public function reenviarSolicitud(int $idSolicitud, string $detalleActualizado, ?object $archivo = null): void
    {
        $solicitud = $this->_solicitudOFail($idSolicitud);

        if ($solicitud['estado_liquidacion'] !== 'Correcion') {
            throw new Exception('Solo se puede reenviar una solicitud que esté en estado de Corrección');
        }

        if ($archivo) {
            $this->_validarArchivo($archivo);
        }

        $this->connect->prepare(
            "UPDATE compras.facturas_sat
         SET    estado_liquidacion = 'Pendiente', fecha_actualizacion = NOW()
         WHERE  id = ?"
        )->execute([$solicitud['id_factura_sat']]);

        $this->connect->prepare(
            "UPDATE bodega_inventario.solicitudes_factura SET detalle = ? WHERE id = ?"
        )->execute([$detalleActualizado, $idSolicitud]);

        $motivo = 'Reenviada por el solicitante con el detalle corregido';

        if ($archivo) {
            $ok = $this->_registrarArchivoFactura(
                $archivo,
                (int)$solicitud['id_factura_sat'],
                $solicitud['numero_dte']
            );
            $motivo .= $ok
                ? ' y el archivo de la factura reemplazado'
                : ' (el archivo adjunto no se pudo subir a Drive)';
        }

        $this->_registrarHistorial($idSolicitud, 'Correccion', 'Pendiente', $motivo);
    }

    /**
     * Edición del encargado mientras la solicitud todavía no ha avanzado.
     * Permitido solo en 'Pendiente' — en 'Correccion' la vía correcta es
     * reenviarSolicitud(), que además devuelve la solicitud a revisión.
     * No cambia el estado; solo deja huella en el historial.
     */
    public function editarSolicitud(int $idSolicitud, string $detalle, ?object $archivo = null): void
    {
        $solicitud = $this->_solicitudOFail($idSolicitud);

        if ($solicitud['estado_liquidacion'] !== 'Pendiente') {
            throw new Exception('Solo se puede editar una solicitud que esté en estado Pendiente');
        }
        if (!empty($solicitud['comprobante_drive_id'])) {
            throw new Exception('La solicitud ya tiene comprobante adjunto y no puede editarse');
        }

        if ($archivo) {
            $this->_validarArchivo($archivo);
        }

        $this->connect->prepare(
            "UPDATE bodega_inventario.solicitudes_factura SET detalle = ? WHERE id = ?"
        )->execute([$detalle, $idSolicitud]);

        $motivo = 'Detalle actualizado por el solicitante';

        if ($archivo) {
            $ok = $this->_registrarArchivoFactura(
                $archivo,
                (int)$solicitud['id_factura_sat'],
                $solicitud['numero_dte']
            );
            $motivo .= $ok
                ? ' y archivo de la factura reemplazado'
                : ' (el archivo adjunto no se pudo subir a Drive)';
        }

        $this->_registrarHistorial($idSolicitud, 'Pendiente', 'Pendiente', $motivo);
    }


// ─────────────────────────────────────────────────────────────────────────────
// 5. AGREGAR _registrarArchivoFactura() — privado
// ─────────────────────────────────────────────────────────────────────────────
    /**
     * Sube el archivo a Drive y lo registra en compras.archivos_facturas_subidas.
     * No borra el anterior: los SELECT siempre leen el MAX(id) por factura, así
     * queda el rastro de las versiones previas.
     *
     * @return bool true si se subió y registró correctamente
     */
    private function _registrarArchivoFactura(object $archivo, int $idFactura, string $numeroDte): bool
    {
        $driveId = $this->_subirArchivoDrive($archivo, $idFactura, $numeroDte, 'Facturas_Subidas');

        if (!$driveId) {
            error_log("FacturaBodegaHelper: no se pudo subir el archivo de la factura {$numeroDte} a Drive");
            return false;
        }

        $extension = strtolower(pathinfo($archivo->name, PATHINFO_EXTENSION));

        $this->connect->prepare(
            "INSERT INTO compras.archivos_facturas_subidas
             (id_factura, drive_id, nombre_original, nombre_en_drive,
              tipo_mime, tamano_bytes, subido_por, fecha_subida)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $idFactura, $driveId, $archivo->name,
            "factura_{$idFactura}_" . time() . '.' . $extension,
            $archivo->type, $archivo->size, $this->idUsuario,
        ]);

        return true;
    }


    public function verificarFactura(int $idSolicitud, ?string $comentario = null): void
    {
        $solicitud = $this->_solicitudOFail($idSolicitud);

        if ($solicitud['estado_liquidacion'] !== 'Pendiente') {
            throw new Exception('Solo se puede verificar una solicitud en estado Pendiente');
        }

        $this->connect->prepare(
            "UPDATE compras.facturas_sat
             SET    estado_liquidacion = 'Verificado', fecha_actualizacion = NOW()
             WHERE  id = ?"
        )->execute([$solicitud['id_factura_sat']]);

        $this->connect->prepare(
            "UPDATE bodega_inventario.solicitudes_factura
             SET    id_usuario_revisor = ?, fecha_revision = NOW()
             WHERE  id = ?"
        )->execute([$this->idUsuario, $idSolicitud]);

        $this->_registrarHistorial($idSolicitud, 'Pendiente', 'Verificado', $comentario);
    }

    /**
     * Sube el comprobante de pago/depósito. Lo sube CONTABILIDAD (no el
     * encargado) al revisar la solicitud. Permitido desde 'Pendiente' o
     * desde 'Verificado' — así contabilidad puede saltarse el checkpoint
     * de verificación cuando ya tiene todo listo, para no ser burocrático.
     * Siempre cierra en 'Pagado'.
     */
    public function subirComprobante(int $idSolicitud, object $archivo): string
    {
        $solicitud = $this->_solicitudOFail($idSolicitud);

        $estadoActual = $solicitud['estado_liquidacion'];
        if (!in_array($estadoActual, ['Pendiente', 'Verificado'], true)) {
            throw new Exception('Solo se puede subir el comprobante desde Pendiente o Verificado');
        }

        $this->_validarArchivo($archivo);

        $driveId = $this->_subirArchivoDrive($archivo, $idSolicitud, $solicitud['numero_dte'] ?? (string)$idSolicitud, 'Comprobantes_Pago');
        if (!$driveId) {
            throw new Exception('No se pudo subir el comprobante, intenta nuevamente');
        }

        $this->connect->prepare(
            "UPDATE bodega_inventario.solicitudes_factura
             SET    comprobante_drive_id = ?, comprobante_nombre = ?,
                    fecha_comprobante = NOW(), id_usuario_comprobante = ?
             WHERE  id = ?"
        )->execute([$driveId, $archivo->name, $this->idUsuario, $idSolicitud]);

        $this->connect->prepare(
            "UPDATE compras.facturas_sat
             SET    estado_liquidacion = 'Pagado', fecha_actualizacion = NOW()
             WHERE  id = ?"
        )->execute([$solicitud['id_factura_sat']]);

        $this->connect->prepare(
            "UPDATE bodega_inventario.solicitudes_factura
             SET    id_usuario_revisor = ?, fecha_revision = NOW()
             WHERE  id = ?
             AND id_usuario_revisor is null"
        )->execute([$this->idUsuario, $idSolicitud]);

        $this->_registrarHistorial($idSolicitud, $estadoActual, 'Pagado', 'Comprobante de pago/depósito adjuntado por contabilidad');

        return $driveId;
    }

    // =========================================================================
    // PRIVADOS
    // =========================================================================

    private function _solicitudOFail(int $idSolicitud): array
    {
        $solicitud = $this->obtenerSolicitud($idSolicitud);
        if (!$solicitud) {
            throw new Exception('La solicitud indicada no existe');
        }
        return $solicitud;
    }

    private function _registrarHistorial(int $idSolicitud, ?string $estadoAnterior, string $estadoNuevo, ?string $motivo): void
    {
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.solicitudes_factura_historial
                 (id_solicitud_factura, id_usuario, estado_anterior, estado_nuevo, motivo, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        )->execute([$idSolicitud, $this->idUsuario, $estadoAnterior, $estadoNuevo, $motivo]);
    }

    /**
     * Comprueba que el drive_id pertenezca a este módulo: o es el archivo de una
     * factura registrada, o es el comprobante de pago de una solicitud.
     *
     * Sin esto, cualquiera con el endpoint podría pedir un drive_id arbitrario y
     * leer archivos de otros módulos.
     */
    public function driveIdPerteneceAlModulo(string $driveId): bool
    {
        $stmt = $this->connect->prepare(
            "SELECT 1
         FROM   compras.archivos_facturas_subidas
         WHERE  drive_id = ?
         LIMIT  1"
        );
        $stmt->execute([$driveId]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $this->connect->prepare(
            "SELECT 1
         FROM   bodega_inventario.solicitudes_factura
         WHERE  comprobante_drive_id = ?
         LIMIT  1"
        );
        $stmt->execute([$driveId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Devuelve el nombre original con el que se guardó el archivo, para que la
     * descarga no salga como "archivo.pdf" genérico.
     */
    public function nombreOriginalPorDriveId(string $driveId): ?string
    {
        $stmt = $this->connect->prepare(
            "SELECT nombre_original
         FROM   compras.archivos_facturas_subidas
         WHERE  drive_id = ?
         ORDER  BY id DESC
         LIMIT  1"
        );
        $stmt->execute([$driveId]);
        $nombre = $stmt->fetchColumn();
        if ($nombre) {
            return (string)$nombre;
        }

        $stmt = $this->connect->prepare(
            "SELECT comprobante_nombre
         FROM   bodega_inventario.solicitudes_factura
         WHERE  comprobante_drive_id = ?
         LIMIT  1"
        );
        $stmt->execute([$driveId]);
        $nombre = $stmt->fetchColumn();

        return $nombre ? (string)$nombre : null;
    }

    /**
     * Listado del Administrador de Bodegas: todas las solicitudes de bodegas de
     * agencia (id_tipo = 1), incluida la de la agencia 99 que es la suya.
     */
    public function listarSolicitudesAgencias(array $filtros = []): array
    {
        return $this->_listar(['b.id_tipo = 1', 'b.activo = 1'], [], $filtros);
    }

    /**
     * Comprueba si una solicitud pertenece a una bodega de agencia. Lo usa el
     * endpoint para decidir si el administrador puede editarla o reenviarla.
     */
    public function solicitudEsDeAgencia(int $idSolicitud): bool
    {
        $stmt = $this->connect->prepare(
            "SELECT 1
         FROM   bodega_inventario.solicitudes_factura sf
         INNER JOIN bodega_inventario.bodegas b ON b.id = sf.id_bodega
         WHERE  sf.id = ? AND b.id_tipo = 1
         LIMIT  1"
        );
        $stmt->execute([$idSolicitud]);

        return (bool)$stmt->fetchColumn();
    }
}