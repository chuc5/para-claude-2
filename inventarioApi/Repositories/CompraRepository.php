<?php

declare(strict_types=1);

namespace App\inventarioApi\Repositories;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoOrigenCompra;
use Exception;
use PDO;

/**
 * CompraRepository
 *
 * Único punto de acceso SQL a `compras` / `compras_detalle`. Los
 * servicios de dominio nunca escriben SQL directamente: piden datos u
 * ordenan cambios de estado a través de este repositorio. Esto hace que
 * un cambio de esquema (ej. renombrar una columna) se resuelva en un solo
 * archivo.
 */
final class CompraRepository
{
    public function __construct(private PDO $connect)
    {
    }

    // ---------------------------------------------------------------
    // Bodegas
    // ---------------------------------------------------------------

    public function obtenerBodegaActiva(int $idBodega): ?object
    {
        $stmt = $this->connect->prepare(
            'SELECT id, id_tipo, id_agencia, restriccion_acceso_activa
             FROM bodega_inventario.bodegas
             WHERE id = ? AND activo = 1
             LIMIT 1'
        );
        $stmt->execute([$idBodega]);

        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    // ---------------------------------------------------------------
    // Creación
    // ---------------------------------------------------------------

    /**
     * @param array{
     *   id_bodega:int, id_tipo_origen:int, id_estado:int,
     *   id_usuario_solicitante?:?string, id_usuario_admin?:?string,
     *   requiere_autorizacion?:int
     * } $cabecera
     */
    public function crearCompra(array $cabecera): int
    {
        $stmt = $this->connect->prepare(
            'INSERT INTO bodega_inventario.compras
                (id_bodega, id_tipo_origen, id_estado, id_usuario_solicitante,
                 id_usuario_admin, requiere_autorizacion)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $cabecera['id_bodega'],
            $cabecera['id_tipo_origen'],
            $cabecera['id_estado'],
            $cabecera['id_usuario_solicitante'] ?? null,
            $cabecera['id_usuario_admin'] ?? null,
            $cabecera['requiere_autorizacion'] ?? 0,
        ]);

        return (int) $this->connect->lastInsertId();
    }

    /** @param array<array{id_producto:int,id_unidad:int,id_bodega_destino:int,cantidad_solicitada:float,justificacion?:?string,serie?:?string,resolucion?:?string,fecha_resolucion?:?string,correlativo_inicial?:?int,correlativo_final?:?int}> $lineas */
    public function agregarLineas(int $idCompra, array $lineas): void
    {
        $stmt = $this->connect->prepare(
            'INSERT INTO bodega_inventario.compras_detalle
                (id_compra, id_producto, id_unidad, id_bodega_destino, cantidad_solicitada, justificacion,
                 serie, resolucion, fecha_resolucion, correlativo_inicial, correlativo_final)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($lineas as $linea) {
            $stmt->execute([
                $idCompra,
                $linea['id_producto'],
                $linea['id_unidad'],
                $linea['id_bodega_destino'],
                $linea['cantidad_solicitada'],
                $linea['justificacion'] ?? null,
                $linea['serie'] ?? null,
                $linea['resolucion'] ?? null,
                $linea['fecha_resolucion'] ?? null,
                $linea['correlativo_inicial'] ?? null,
                $linea['correlativo_final'] ?? null,
            ]);
        }
    }

    /**
     * Borra TODAS las líneas de una compra. Se usa exclusivamente para
     * "editarSolicitud" (mientras la compra sigue en SOLICITADA): el
     * encargado reemplaza el detalle completo, como si llenara el
     * formulario de nuevo. CompraService es quien garantiza el estado
     * antes de invocar esto — este repositorio no valida reglas de negocio.
     */
    public function eliminarLineas(int $idCompra): void
    {
        $this->connect->prepare(
            'DELETE FROM bodega_inventario.compras_detalle WHERE id_compra = ?'
        )->execute([$idCompra]);
    }

    // ---------------------------------------------------------------
    // Lectura con bloqueo (para transiciones de estado)
    // ---------------------------------------------------------------

    public function obtenerCompraConBloqueo(int $idCompra): ?object
    {
        $stmt = $this->connect->prepare(
            'SELECT c.id, c.id_bodega, c.id_tipo_origen, c.id_estado,
                    c.id_usuario_solicitante, c.id_usuario_gestor,
                    c.id_usuario_admin, c.requiere_autorizacion,
                    b.id_tipo AS tipo_bodega_gestion
             FROM bodega_inventario.compras c
             INNER JOIN bodega_inventario.bodegas b ON b.id = c.id_bodega
             WHERE c.id = ?
             FOR UPDATE'
        );
        $stmt->execute([$idCompra]);

        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    public function obtenerLineaConBloqueo(int $idCompra, int $idLinea): ?object
    {
        $stmt = $this->connect->prepare(
            'SELECT d.id, d.id_compra, d.id_producto, d.id_unidad, d.id_bodega_destino,
                    d.cantidad_solicitada, d.cantidad_ajustada, d.cantidad_final,
                    d.precio_unitario, d.comprado_con_precio, d.id_alta_generada,
                    b.id_tipo AS tipo_bodega_destino
             FROM bodega_inventario.compras_detalle d
             INNER JOIN bodega_inventario.bodegas b ON b.id = d.id_bodega_destino
             WHERE d.id = ? AND d.id_compra = ?
             FOR UPDATE'
        );
        $stmt->execute([$idLinea, $idCompra]);

        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    /** @return object[] */
    public function obtenerLineas(int $idCompra): array
    {
        $stmt = $this->connect->prepare(
            'SELECT d.id, d.id_producto, p.nombre AS producto, p.id_tipo AS id_tipo_producto,
                    d.id_unidad, u.nombre AS unidad, u.abreviatura,
                    d.id_bodega_destino, bd.nombre AS bodega_destino, bd.id_tipo AS tipo_bodega_destino,
                    d.cantidad_solicitada, d.cantidad_ajustada, d.cantidad_final,
                    d.precio_unitario, d.comprado_con_precio, d.fecha_marcado_comprado,
                    d.id_factura, d.id_alta_generada, d.justificacion,
                    d.serie, d.resolucion, d.fecha_resolucion, d.correlativo_inicial, d.correlativo_final
             FROM bodega_inventario.compras_detalle d
             INNER JOIN bodega_inventario.productos p ON p.id = d.id_producto
             INNER JOIN bodega_inventario.unidades_medida u ON u.id = d.id_unidad
             INNER JOIN bodega_inventario.bodegas bd ON bd.id = d.id_bodega_destino
             WHERE d.id_compra = ?
             ORDER BY d.id ASC'
        );
        $stmt->execute([$idCompra]);

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Actualiza el rango de correlativo de una línea — usado por el gestor
     * al aprobar (junto con ajustarCantidadLinea), para que el correlativo
     * declarado concuerde con la cantidad que finalmente se aprobó.
     */
    public function actualizarCorrelativoLinea(
        int $idLinea,
        ?string $serie,
        ?string $resolucion,
        ?string $fechaResolucion,
        ?int $correlativoInicial,
        ?int $correlativoFinal,
    ): void {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras_detalle
             SET serie = ?, resolucion = ?, fecha_resolucion = ?, correlativo_inicial = ?, correlativo_final = ?
             WHERE id = ?'
        )->execute([$serie, $resolucion, $fechaResolucion, $correlativoInicial, $correlativoFinal, $idLinea]);
    }

    /**
     * Cabecera completa de UNA compra (mismos campos que listar(), pero para
     * un solo registro). Se usa en los modales de detalle / gestión, donde
     * ya no basta el resumen de líneas que trae obtenerLineasResumenPorCompras().
     */
    public function obtenerCabecera(int $idCompra): ?object
    {
        $stmt = $this->connect->prepare(
            "SELECT c.id, c.id_bodega, b.nombre AS bodega, c.id_tipo_origen, c.id_estado,
                    c.id_usuario_solicitante, COALESCE(dps.nombres, c.id_usuario_solicitante) AS nombre_solicitante,
                    c.id_usuario_gestor, COALESCE(dpg.nombres, c.id_usuario_gestor) AS nombre_gestor,
                    c.comentario_gestor, c.fecha_gestion,
                    c.requiere_autorizacion, c.created_at
             FROM bodega_inventario.compras c
             INNER JOIN bodega_inventario.bodegas b ON b.id = c.id_bodega
             LEFT JOIN dbintranet.usuarios us ON us.idUsuarios = c.id_usuario_solicitante
             LEFT JOIN dbintranet.datospersonales dps ON dps.idDatosPersonales = us.idDatosPersonales
             LEFT JOIN dbintranet.usuarios ug ON ug.idUsuarios = c.id_usuario_gestor
             LEFT JOIN dbintranet.datospersonales dpg ON dpg.idDatosPersonales = ug.idDatosPersonales
             WHERE c.id = ?
             LIMIT 1"
        );
        $stmt->execute([$idCompra]);

        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    // ---------------------------------------------------------------
    // Escritura — transiciones de estado
    // ---------------------------------------------------------------

    public function actualizarEstado(int $idCompra, EstadoCompra $estado): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras SET id_estado = ? WHERE id = ?'
        )->execute([$estado->value, $idCompra]);
    }

    public function registrarGestion(int $idCompra, EstadoCompra $estado, string $idUsuarioGestor, string $comentario): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras
             SET id_estado = ?, id_usuario_gestor = ?, comentario_gestor = ?, fecha_gestion = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([$estado->value, $idUsuarioGestor, $comentario, $idCompra]);
    }

    public function registrarAutorizacion(int $idCompra, EstadoCompra $estado, string $idUsuarioAutorizador, string $comentario): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras
             SET id_estado = ?, id_usuario_autorizador = ?, comentario_autorizacion = ?, fecha_autorizacion = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([$estado->value, $idUsuarioAutorizador, $comentario, $idCompra]);
    }

    public function actualizarRequiereAutorizacion(int $idCompra, bool $requiere): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras SET requiere_autorizacion = ? WHERE id = ?'
        )->execute([$requiere ? 1 : 0, $idCompra]);
    }

    // ---------------------------------------------------------------
    // Escritura — líneas
    // ---------------------------------------------------------------

    public function ajustarCantidadLinea(int $idLinea, float $cantidad): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras_detalle SET cantidad_ajustada = ? WHERE id = ?'
        )->execute([$cantidad, $idLinea]);
    }

    /**
     * Redirige una línea a otra bodega destino — mesa de trabajo. Reemplaza
     * el `id_bodega_destino` que se fijó al crear la compra (por defecto,
     * la misma bodega de la compra) por uno distinto. `enviar()` ya usa el
     * `id_bodega_destino` de cada línea individualmente, así que el alta
     * nace directo en la bodega correcta sin tocar nada más.
     */
    public function actualizarBodegaDestinoLinea(int $idLinea, int $idBodegaDestino): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras_detalle SET id_bodega_destino = ? WHERE id = ?'
        )->execute([$idBodegaDestino, $idLinea]);
    }

    /**
     * Deja constancia de cada redirección de bodega destino — quién la
     * hizo, cuándo, y de dónde a dónde. `compras.id_bodega` (bodega_origen
     * en el listado de la mesa de trabajo) sigue siendo la bodega original
     * de creación y nunca se toca; esta tabla es el historial de los
     * cambios posteriores hechos en la mesa de trabajo.
     */
    public function registrarHistorialDestino(int $idLinea, int $idBodegaAnterior, int $idBodegaNueva, string $idUsuario): void
    {
        $this->connect->prepare(
            'INSERT INTO bodega_inventario.compras_detalle_historial_destino
                (id_linea, id_bodega_anterior, id_bodega_nueva, id_usuario)
             VALUES (?, ?, ?, ?)'
        )->execute([$idLinea, $idBodegaAnterior, $idBodegaNueva, $idUsuario]);
    }

    /** Historial de redirecciones de una línea, más reciente primero. */
    public function obtenerHistorialDestino(int $idLinea): array
    {
        $stmt = $this->connect->prepare(
            'SELECT h.id, h.id_bodega_anterior, ba.nombre AS bodega_anterior,
                    h.id_bodega_nueva, bn.nombre AS bodega_nueva,
                    h.id_usuario, h.created_at
             FROM bodega_inventario.compras_detalle_historial_destino h
             INNER JOIN bodega_inventario.bodegas ba ON ba.id = h.id_bodega_anterior
             INNER JOIN bodega_inventario.bodegas bn ON bn.id = h.id_bodega_nueva
             WHERE h.id_linea = ?
             ORDER BY h.created_at DESC'
        );
        $stmt->execute([$idLinea]);

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function marcarLineaComprada(int $idLinea, float $precioUnitario): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras_detalle
             SET precio_unitario = ?, comprado_con_precio = 1, fecha_marcado_comprado = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([$precioUnitario, $idLinea]);
    }

    /**
     * Autoguardado de precio — mesa de trabajo. Deja el precio persistido
     * en cuanto el usuario sale del campo, SIN marcar comprado_con_precio
     * ni disparar el auto-envío; eso solo pasa al confirmar con
     * marcarLineaComprada(). Así, un refresh de página o un cierre
     * accidental de sesión nunca pierde lo que ya se tecleó.
     */
    public function guardarPrecioBorrador(int $idLinea, float $precioUnitario): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras_detalle SET precio_unitario = ? WHERE id = ?'
        )->execute([$precioUnitario, $idLinea]);
    }

    public function vincularAlta(int $idLinea, int $idAlta): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras_detalle SET id_alta_generada = ? WHERE id = ?'
        )->execute([$idAlta, $idLinea]);
    }

    /**
     * Inserta el registro físico en `altas` a partir de una línea de
     * compra ya comprada. Vive en este repositorio (y no en un
     * AltaRepository) porque generar el alta es la última transición del
     * ciclo de vida de la compra, no una operación independiente.
     */
    public function insertarAlta(int $idCompra, object $linea, string $idUsuarioAdmin): int
    {
        $stmt = $this->connect->prepare(
            'INSERT INTO bodega_inventario.altas
                (id_bodega_destino, id_producto, id_unidad, cantidad_enviada,
                 cantidad_ingresada, id_estado, id_usuario_admin, id_compra, precio_unitario)
             VALUES (?, ?, ?, ?, 0.00, 1, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $linea->id_bodega_destino,
            (int) $linea->id_producto,
            (int) $linea->id_unidad,
            (float) $linea->cantidad_final,
            $idUsuarioAdmin,
            $idCompra,
            (float) $linea->precio_unitario,
        ]);

        return (int) $this->connect->lastInsertId();
    }


    // ---------------------------------------------------------------
    // Consultas agregadas de apoyo a las transiciones
    // ---------------------------------------------------------------

    public function contarLineasSinComprar(int $idCompra): int
    {
        $stmt = $this->connect->prepare(
            'SELECT COUNT(*) FROM bodega_inventario.compras_detalle
             WHERE id_compra = ? AND comprado_con_precio = 0'
        );
        $stmt->execute([$idCompra]);

        return (int) $stmt->fetchColumn();
    }

    public function hayLineasEnAlza(int $idCompra): bool
    {
        $stmt = $this->connect->prepare(
            'SELECT COUNT(*) FROM bodega_inventario.compras_detalle
             WHERE id_compra = ? AND cantidad_ajustada IS NOT NULL AND cantidad_ajustada > cantidad_solicitada'
        );
        $stmt->execute([$idCompra]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Líneas compradas y sin alta generada todavía, listas para pasar a `altas`. */
    public function obtenerLineasParaAlta(int $idCompra): array
    {
        $stmt = $this->connect->prepare(
            'SELECT id, id_producto, id_unidad, id_bodega_destino, cantidad_final, precio_unitario
             FROM bodega_inventario.compras_detalle
             WHERE id_compra = ? AND comprado_con_precio = 1 AND id_alta_generada IS NULL'
        );
        $stmt->execute([$idCompra]);

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    // ---------------------------------------------------------------
    // Listados / bandejas
    // ---------------------------------------------------------------

    /**
     * Consulta genérica para bandejas: aplica un WHERE arbitrario ya
     * armado por el llamador (cada servicio de flujo conoce su propio
     * filtro de acceso) y devuelve cabecera + total, paginado.
     */
    public function listar(string $whereSql, array $params, int $pagina, int $porPagina): array
    {
        $pagina    = max(1, $pagina);
        $porPagina = min(50, max(1, $porPagina));
        $offset    = ($pagina - 1) * $porPagina;

        // LEFT JOIN (no INNER): un usuario dado de baja en dbintranet no debe
        // ocultar la compra — en ese caso el nombre queda NULL y el front cae
        // de vuelta al id crudo (ver COALESCE más abajo).
        $sqlBase = "FROM bodega_inventario.compras c
            INNER JOIN bodega_inventario.bodegas b ON b.id = c.id_bodega
            LEFT JOIN dbintranet.usuarios us ON us.idUsuarios = c.id_usuario_solicitante
            LEFT JOIN dbintranet.datospersonales dps ON dps.idDatosPersonales = us.idDatosPersonales
            LEFT JOIN dbintranet.usuarios ug ON ug.idUsuarios = c.id_usuario_gestor
            LEFT JOIN dbintranet.datospersonales dpg ON dpg.idDatosPersonales = ug.idDatosPersonales
            WHERE {$whereSql}";

        $stmtCount = $this->connect->prepare("SELECT COUNT(*) AS total {$sqlBase}");
        $stmtCount->execute($params);
        $total = (int) ($stmtCount->fetch(PDO::FETCH_OBJ)->total ?? 0);

        $stmt = $this->connect->prepare(
            "SELECT c.id, c.id_bodega, b.nombre AS bodega, c.id_tipo_origen, c.id_estado,
                    c.id_usuario_solicitante, COALESCE(dps.nombres, c.id_usuario_solicitante) AS nombre_solicitante,
                    c.id_usuario_gestor, COALESCE(dpg.nombres, c.id_usuario_gestor) AS nombre_gestor,
                    c.comentario_gestor, c.fecha_gestion,
                    c.requiere_autorizacion, c.created_at
             {$sqlBase}
             ORDER BY c.created_at DESC
             LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'compras'    => $stmt->fetchAll(PDO::FETCH_OBJ),
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $porPagina,
        ];
    }

    /** Líneas resumidas de varias compras a la vez, para preview en bandejas (evita N+1). */
    public function obtenerLineasResumenPorCompras(array $idsCompras): array
    {
        if (empty($idsCompras)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($idsCompras), '?'));
        $stmt = $this->connect->prepare(
            "SELECT d.id_compra, d.id_producto, p.nombre AS producto, d.id_unidad, u.abreviatura,
                    d.cantidad_solicitada, d.cantidad_ajustada, d.cantidad_final, d.justificacion,
                    d.serie, d.resolucion, d.correlativo_inicial, d.correlativo_final
             FROM bodega_inventario.compras_detalle d
             INNER JOIN bodega_inventario.productos p ON p.id = d.id_producto
             INNER JOIN bodega_inventario.unidades_medida u ON u.id = d.id_unidad
             WHERE d.id_compra IN ({$placeholders})
             ORDER BY d.id ASC"
        );
        $stmt->execute($idsCompras);

        $porCompra = [];
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $l) {
            $porCompra[(int) $l->id_compra][] = $l;
        }

        return $porCompra;
    }

    // ---------------------------------------------------------------
    // Mesa de trabajo — consulta plana por LÍNEA (no por compra), a través
    // de todos los orígenes (agencia, área, trimestral, extraordinaria).
    // ---------------------------------------------------------------

    /**
     * @param array{
     *   id_tipo_producto?: ?int, id_bodega_destino?: ?int, id_tipo_origen?: ?int,
     *   comprado?: ?bool, busqueda?: ?string
     * } $filtros
     */
    /**
     * @param array{
     *   id_tipo_producto?: ?int, id_bodega_destino?: ?int, id_tipo_origen?: ?int,
     *   comprado?: ?bool, busqueda?: ?string
     * } $filtros
     * @param array<int> $estados Estados de compra a incluir — normalmente
     *   [Aprobada, Comprada] para el trabajo diario; se puede pasar además
     *   [Enviada] para el filtro "Enviadas" / la descarga de Excel, ya que
     *   una compra Enviada sale del alcance normal de la mesa de trabajo.
     */
    public function listarLineasMesaTrabajo(int $idTipoBodegaDestino, array $filtros, array $estados, int $pagina, int $porPagina): array
    {
        $pagina    = max(1, $pagina);
        $porPagina = min(5000, max(1, $porPagina)); // hasta 5000 para permitir "descargar todo" en el Excel
        $offset    = ($pagina - 1) * $porPagina;

        $placeholdersEstado = implode(',', array_fill(0, count($estados), '?'));
        $where  = ['bd.id_tipo = ?', "c.id_estado IN ({$placeholdersEstado})"];
        $params = array_merge([$idTipoBodegaDestino], $estados);

        if (!empty($filtros['id_tipo_producto'])) {
            $where[]  = 'p.id_tipo = ?';
            $params[] = (int) $filtros['id_tipo_producto'];
        }
        if (!empty($filtros['id_bodega_destino'])) {
            $where[]  = 'd.id_bodega_destino = ?';
            $params[] = (int) $filtros['id_bodega_destino'];
        }
        if (!empty($filtros['id_tipo_origen'])) {
            $where[]  = 'c.id_tipo_origen = ?';
            $params[] = (int) $filtros['id_tipo_origen'];
        }
        if (isset($filtros['comprado']) && $filtros['comprado'] !== null) {
            $where[]  = 'd.comprado_con_precio = ?';
            $params[] = $filtros['comprado'] ? 1 : 0;
        }
        if (!empty($filtros['busqueda'])) {
            $where[]  = '(p.nombre LIKE ? OR bd.nombre LIKE ?)';
            $like     = '%' . $filtros['busqueda'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $sqlBase = "FROM bodega_inventario.compras_detalle d
            INNER JOIN bodega_inventario.compras c ON c.id = d.id_compra
            INNER JOIN bodega_inventario.productos p ON p.id = d.id_producto
            INNER JOIN bodega_inventario.unidades_medida u ON u.id = d.id_unidad
            INNER JOIN bodega_inventario.bodegas bd ON bd.id = d.id_bodega_destino
            INNER JOIN bodega_inventario.bodegas bo ON bo.id = c.id_bodega
            LEFT JOIN dbintranet.usuarios us ON us.idUsuarios = c.id_usuario_solicitante
            LEFT JOIN dbintranet.datospersonales dps ON dps.idDatosPersonales = us.idDatosPersonales
            WHERE {$whereSql}";

        $stmtCount = $this->connect->prepare("SELECT COUNT(*) AS total {$sqlBase}");
        $stmtCount->execute($params);
        $total = (int) ($stmtCount->fetch(PDO::FETCH_OBJ)->total ?? 0);

        $stmt = $this->connect->prepare(
            "SELECT d.id AS id_linea, d.id_compra,
                    c.id_tipo_origen, c.id_estado, c.created_at AS fecha_compra,
                    c.id_usuario_solicitante, COALESCE(dps.nombres, c.id_usuario_solicitante) AS nombre_solicitante,
                    d.id_producto, p.nombre AS producto, p.id_tipo AS id_tipo_producto,
                    d.id_unidad, u.abreviatura,
                    d.id_bodega_destino, bd.nombre AS bodega_destino,
                    bo.id AS id_bodega_origen, bo.nombre AS bodega_origen,
                    d.cantidad_solicitada, d.cantidad_ajustada, d.cantidad_final,
                    d.precio_unitario, d.comprado_con_precio, d.justificacion,
                    d.id_alta_generada,
                    d.serie, d.resolucion, d.fecha_resolucion,
                    d.correlativo_inicial, d.correlativo_final
             {$sqlBase}
             ORDER BY c.created_at ASC, p.nombre ASC
             LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'lineas'     => $stmt->fetchAll(PDO::FETCH_OBJ),
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $porPagina,
        ];
    }

    /** Bodegas activas de un tipo dado — para el selector de "cambiar bodega destino". */
    public function listarBodegasPorTipo(int $idTipo): array
    {
        $stmt = $this->connect->prepare(
            'SELECT id, nombre FROM bodega_inventario.bodegas WHERE id_tipo = ? AND activo = 1 ORDER BY nombre ASC'
        );
        $stmt->execute([$idTipo]);

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    // ---------------------------------------------------------------
    // Dar de alta directa (mesa de trabajo de Área) — las altas ya las
    // crea CompraService::enviar()/insertarAlta(); estos métodos son para
    // completarlas de inmediato con UN lote por alta, sin pasar por el
    // módulo de Altas normal (que sí soporta varios lotes parciales).
    // ---------------------------------------------------------------

    /** Altas generadas para una compra — para saber cuáles necesitan datos de lote antes de completarlas. */
    public function obtenerAltasPorCompra(int $idCompra): array
    {
        $stmt = $this->connect->prepare(
            'SELECT a.id, a.id_producto, p.nombre AS producto, p.id_tipo AS id_tipo_producto,
                    a.id_unidad, u.abreviatura, a.id_bodega_destino,
                    a.cantidad_enviada, a.cantidad_ingresada, a.id_estado, a.precio_unitario
             FROM bodega_inventario.altas a
             INNER JOIN bodega_inventario.productos p ON p.id = a.id_producto
             INNER JOIN bodega_inventario.unidades_medida u ON u.id = a.id_unidad
             WHERE a.id_compra = ?'
        );
        $stmt->execute([$idCompra]);

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function crearLoteNormal(int $idBodega, int $idProducto, int $idUnidad, float $cantidad, int $idAlta, string $idUsuarioEncargado, ?float $precioUnitario): int
    {
        $this->connect->prepare(
            'INSERT INTO bodega_inventario.lotes_normal
                (id_bodega, id_producto, id_unidad, cantidad_disponible, fecha_ingreso, id_alta, id_usuario_encargado, precio_unitario)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)'
        )->execute([$idBodega, $idProducto, $idUnidad, $cantidad, $idAlta, $idUsuarioEncargado, $precioUnitario]);

        return (int) $this->connect->lastInsertId();
    }

    public function crearLoteExpiracion(int $idBodega, int $idProducto, int $idUnidad, string $fechaExpiracion, float $cantidad, int $idAlta, string $idUsuarioEncargado, ?float $precioUnitario): int
    {
        $this->connect->prepare(
            'INSERT INTO bodega_inventario.lotes_expiracion
                (id_bodega, id_producto, id_unidad, fecha_expiracion, cantidad_disponible, cantidad_reservada, id_alta, id_usuario_encargado, precio_unitario)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)'
        )->execute([$idBodega, $idProducto, $idUnidad, $fechaExpiracion, $cantidad, $idAlta, $idUsuarioEncargado, $precioUnitario]);

        return (int) $this->connect->lastInsertId();
    }

    /**
     * @throws Exception si el rango se solapa con un lote activo del mismo
     *   producto/bodega — MySQL lo rechaza con un trigger (SIGNAL 45000);
     *   se traduce a un mensaje entendible en vez del error crudo de PDO.
     */
    public function crearLoteCorrelativo(
        int $idBodega, int $idProducto, ?string $serie, ?string $resolucion, ?string $fechaResolucion,
        int $correlativoInicial, int $correlativoFinal, int $cantidad, int $idAlta, string $idUsuarioEncargado, ?float $precioUnitario
    ): int {
        try {
            $this->connect->prepare(
                'INSERT INTO bodega_inventario.lotes_correlativo
                    (id_bodega, id_producto, serie, resolucion, fecha_resolucion,
                     correlativo_inicial, correlativo_final, correlativo_siguiente,
                     cantidad_disponible, cantidad_reservada, id_alta, id_usuario_encargado, precio_unitario)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
            )->execute([
                $idBodega, $idProducto, $serie, $resolucion, $fechaResolucion,
                $correlativoInicial, $correlativoFinal, $correlativoInicial,
                $cantidad, $idAlta, $idUsuarioEncargado, $precioUnitario,
            ]);
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1644 || str_contains($e->getMessage(), 'solapa')) {
                throw new Exception("El rango de correlativo {$correlativoInicial}-{$correlativoFinal} se solapa con un lote activo existente para este producto — elija otro rango inicial.");
            }
            throw $e;
        }

        return (int) $this->connect->lastInsertId();
    }

    public function ingresarLoteEnAlta(int $idAlta, float $cantidadIngresadaActual, float $cantidadEnviada, float $cantidadNueva): void
    {
        $nuevaIngresada = $cantidadIngresadaActual + $cantidadNueva;
        $nuevoEstado    = $nuevaIngresada >= $cantidadEnviada ? 3 : 2;

        $this->connect->prepare(
            'UPDATE bodega_inventario.altas SET cantidad_ingresada = ?, id_estado = ? WHERE id = ?'
        )->execute([$nuevaIngresada, $nuevoEstado, $idAlta]);
    }

    public function incrementarStockPorAlta(int $idBodega, int $idProducto, int $idUnidad, float $cantidad): void
    {
        $this->connect->prepare(
            'INSERT INTO bodega_inventario.stock (id_bodega, id_producto, id_unidad, cantidad_total, cantidad_reservada)
             VALUES (?, ?, ?, ?, 0.00)
             ON DUPLICATE KEY UPDATE cantidad_total = cantidad_total + ?'
        )->execute([$idBodega, $idProducto, $idUnidad, $cantidad, $cantidad]);
    }

    public function registrarMovimientoAltaCompra(int $idBodega, int $idProducto, int $idUnidad, float $cantidad, string $tablaLote, int $idLote, string $idUsuario, ?float $precioUnitario): void
    {
        $this->connect->prepare(
            'INSERT INTO bodega_inventario.movimientos_stock
                (id_bodega, id_producto, id_unidad, id_tipo_movimiento, cantidad, precio_unitario, entidad_origen, id_entidad_origen, id_usuario)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)'
        )->execute([$idBodega, $idProducto, $idUnidad, $cantidad, $precioUnitario, $tablaLote, $idLote, $idUsuario]);
    }

    // ---------------------------------------------------------------
    // Carga masiva
    // ---------------------------------------------------------------

    public function existeComboProductoUnidadActivo(int $idProducto, int $idUnidad): bool
    {
        $stmt = $this->connect->prepare(
            'SELECT 1 FROM bodega_inventario.productos_unidades pu
             INNER JOIN bodega_inventario.productos p ON p.id = pu.id_producto
             WHERE pu.id_producto = ? AND pu.id_unidad = ? AND pu.activo = 1 AND p.activo = 1
             LIMIT 1'
        );
        $stmt->execute([$idProducto, $idUnidad]);

        return (bool) $stmt->fetchColumn();
    }

    public function crearCargaMasiva(string $tipo, string $idUsuario, int $totalFilas): int
    {
        $this->connect->prepare(
            'INSERT INTO bodega_inventario.cargas_masivas (tipo, id_usuario, total_filas) VALUES (?, ?, ?)'
        )->execute([$tipo, $idUsuario, $totalFilas]);

        return (int) $this->connect->lastInsertId();
    }

    public function vincularCompraACarga(int $idCompra, int $idCargaMasiva): void
    {
        $this->connect->prepare(
            'UPDATE bodega_inventario.compras SET id_carga_masiva = ? WHERE id = ?'
        )->execute([$idCargaMasiva, $idCompra]);
    }

    // ---------------------------------------------------------------
    // Eliminación / reversa de carga masiva
    // ---------------------------------------------------------------

    public function obtenerCargaMasiva(int $idCargaMasiva): ?object
    {
        $stmt = $this->connect->prepare('SELECT * FROM bodega_inventario.cargas_masivas WHERE id = ?');
        $stmt->execute([$idCargaMasiva]);
        $fila = $stmt->fetch(PDO::FETCH_OBJ);

        return $fila ?: null;
    }

    /** Cargas masivas de hoy, de un tipo dado — para que el usuario elija cuál eliminar. */
    public function listarCargasMasivasDeHoy(string $tipo): array
    {
        $stmt = $this->connect->prepare(
            'SELECT 
            cm.id, 
            cm.tipo, 
            #cm.id_usuario, 
            COALESCE(dp.nombres, u.usuario, cm.id_usuario) AS id_usuario,
            cm.total_filas, 
            cm.created_at
         FROM bodega_inventario.cargas_masivas cm
         LEFT JOIN dbintranet.usuarios u ON u.idUsuarios = cm.id_usuario
         LEFT JOIN dbintranet.datospersonales dp ON dp.idDatosPersonales = u.idDatosPersonales
         WHERE cm.tipo = ? AND DATE(cm.created_at) = CURDATE()
         ORDER BY cm.created_at DESC'
        );
        $stmt->execute([$tipo]);

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /** Compras individuales dentro de una carga — para poder elegir eliminar solo una. */
    public function listarComprasDeCarga(int $idCargaMasiva): array
    {
        $stmt = $this->connect->prepare(
            'SELECT c.id, c.id_bodega, b.nombre AS bodega, c.id_estado, es.nombre AS estado,
                    c.created_at, COUNT(d.id) AS total_lineas,
                    GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ", ") AS productos
             FROM bodega_inventario.compras c
             INNER JOIN bodega_inventario.bodegas b ON b.id = c.id_bodega
             INNER JOIN bodega_inventario.estados_compra_v2 es ON es.id = c.id_estado
             LEFT JOIN bodega_inventario.compras_detalle d ON d.id_compra = c.id
             LEFT JOIN bodega_inventario.productos p ON p.id = d.id_producto
             WHERE c.id_carga_masiva = ?
             GROUP BY c.id
             ORDER BY c.id ASC'
        );
        $stmt->execute([$idCargaMasiva]);

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function obtenerCompraParaEliminar(int $idCompra): ?object
    {
        $stmt = $this->connect->prepare(
            'SELECT c.id, c.id_bodega, c.id_tipo_origen, c.id_estado, c.created_at, c.id_carga_masiva,
                    b.id_tipo AS id_tipo_bodega
             FROM bodega_inventario.compras c
             INNER JOIN bodega_inventario.bodegas b ON b.id = c.id_bodega
             WHERE c.id = ?
             FOR UPDATE'
        );
        $stmt->execute([$idCompra]);
        $fila = $stmt->fetch(PDO::FETCH_OBJ);

        return $fila ?: null;
    }

    /** El lote que se creó para esa alta (si ya llegó a Registrada) — para validar que no se haya consumido. */
    public function obtenerLotePorAlta(int $idAlta, int $idTipoProducto): ?object
    {
        $tabla = match ($idTipoProducto) {
            1 => 'lotes_correlativo',
            2 => 'lotes_expiracion',
            default => 'lotes_normal',
        };

        $stmt = $this->connect->prepare("SELECT * FROM bodega_inventario.{$tabla} WHERE id_alta = ? FOR UPDATE");
        $stmt->execute([$idAlta]);
        $fila = $stmt->fetch(PDO::FETCH_OBJ);

        if ($fila) {
            $fila->_tabla = $tabla; // metadato interno, no es columna real
        }

        return $fila ?: null;
    }

    public function eliminarLote(string $tabla, int $idLote): void
    {
        $this->connect->prepare("DELETE FROM bodega_inventario.{$tabla} WHERE id = ?")->execute([$idLote]);
    }

    public function eliminarAltasPorCompra(int $idCompra): void
    {
        $this->connect->prepare('DELETE FROM bodega_inventario.altas WHERE id_compra = ?')->execute([$idCompra]);
    }

    public function eliminarLineasCompra(int $idCompra): void
    {
        $this->connect->prepare('DELETE FROM bodega_inventario.compras_detalle WHERE id_compra = ?')->execute([$idCompra]);
    }

    public function eliminarCompra(int $idCompra): void
    {
        $this->connect->prepare('DELETE FROM bodega_inventario.compras WHERE id = ?')->execute([$idCompra]);
    }
}