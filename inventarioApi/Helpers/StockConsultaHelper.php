<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * StockConsultaHelper (v2)
 *
 * CAMBIOS vs versión anterior:
 *   - listar() ahora agrupa POR PRODUCTO (no por producto+unidad). Si un
 *     producto tiene varias unidades/tallas, se ve como UNA sola fila con
 *     el total sumado y `total_unidades` indicando cuántas tiene.
 *   - NUEVO: unidadesProducto() — mismo contrato que
 *     SolicitudesHelper::obtenerUnidadesProducto, pero mirando `stock` en
 *     lugar de disponibilidad-para-solicitud. Se usa para el selector de
 *     talla dentro del modal de Entrega Directa quien lo necesite
 *     (normal/expiración con más de 1 unidad; correlativo no aplica,
 *     porque ahí el "rango" cumple ese rol).
 *   - contadores() ahora cuenta productos distintos, no filas producto+unidad.
 */
class StockConsultaHelper
{
    private PDO $connect;

    private const TIPO_CORRELATIVO = 1;
    private const TIPO_EXPIRACION  = 2;
    private const TIPO_NORMAL      = 3;

    public function __construct(PDO $connect)
    {
        $this->connect = $connect;
    }

    // =========================================================================
    // LISTADO PRINCIPAL — agrupado por producto
    // =========================================================================

    public function listar(int $idBodega, array $filtros): array
    {
        [$where, $params] = $this->_construirFiltros($idBodega, $filtros);

        $pagina    = max(1, (int)($filtros['pagina'] ?? 1));
        $porPagina = (int)($filtros['por_pagina'] ?? 20);
        $porPagina = ($porPagina < 1 || $porPagina > 100) ? 20 : $porPagina;
        $offset    = ($pagina - 1) * $porPagina;

        $sqlTotal = "SELECT COUNT(DISTINCT s.id_producto)
                     FROM bodega_inventario.stock s
                     INNER JOIN bodega_inventario.productos p ON p.id = s.id_producto
                     WHERE {$where}";
        $stmtTotal = $this->connect->prepare($sqlTotal);
        $stmtTotal->execute($params);
        $total = (int)$stmtTotal->fetchColumn();

        $orderBy = $this->_resolverOrden($filtros['orden'] ?? 'nombre', $filtros['direccion'] ?? 'asc');

        // Nota: al agrupar por producto, "unidad"/"abreviatura" solo tienen
        // sentido cuando el producto tiene una única unidad — se traen con
        // MIN() como representativas y el front las oculta si total_unidades > 1.
        $sql = "SELECT
                    s.id_producto,
                    p.nombre AS producto, p.descripcion, p.id_tipo,
                    tp.nombre AS tipo_producto,
                    p.id_categoria, cp.nombre AS categoria,
                    COUNT(DISTINCT s.id_unidad) AS total_unidades,
                    MIN(s.id_unidad) AS id_unidad_representativa,
                    MIN(um.nombre) AS unidad, MIN(um.abreviatura) AS abreviatura,
                    SUM(s.cantidad_total) AS cantidad_total,
                    SUM(s.cantidad_reservada) AS cantidad_reservada,
                    SUM(s.cantidad_disponible) AS cantidad_disponible,
                    MAX(s.updated_at) AS updated_at,
                    (SELECT MIN(le.fecha_expiracion)
                       FROM bodega_inventario.lotes_expiracion le
                      WHERE le.id_bodega = s.id_bodega
                        AND le.id_producto = s.id_producto
                        AND le.cantidad_disponible > 0) AS proximo_vencimiento,
                    (SELECT lc.correlativo_siguiente
                       FROM bodega_inventario.lotes_correlativo lc
                      WHERE lc.id_bodega = s.id_bodega AND lc.id_producto = s.id_producto
                        AND (lc.cantidad_disponible - lc.cantidad_reservada) > 0
                      ORDER BY lc.correlativo_inicial ASC LIMIT 1) AS proximo_correlativo_inicio,
                    (SELECT lc.correlativo_final
                       FROM bodega_inventario.lotes_correlativo lc
                      WHERE lc.id_bodega = s.id_bodega AND lc.id_producto = s.id_producto
                        AND (lc.cantidad_disponible - lc.cantidad_reservada) > 0
                      ORDER BY lc.correlativo_inicial ASC LIMIT 1) AS proximo_correlativo_fin
                FROM bodega_inventario.stock s
                INNER JOIN bodega_inventario.productos p        ON p.id  = s.id_producto
                INNER JOIN bodega_inventario.tipos_producto tp   ON tp.id = p.id_tipo
                INNER JOIN bodega_inventario.categorias_producto cp ON cp.id = p.id_categoria
                LEFT JOIN bodega_inventario.unidades_medida um   ON um.id = s.id_unidad
                WHERE {$where}
                GROUP BY s.id_producto, p.nombre, p.descripcion, p.id_tipo, tp.nombre, p.id_categoria, cp.nombre
                ORDER BY {$orderBy}
                LIMIT {$porPagina} OFFSET {$offset}";

        $stmt = $this->connect->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$item) {
            $item['cantidad_total']       = (float)$item['cantidad_total'];
            $item['cantidad_reservada']   = (float)$item['cantidad_reservada'];
            $item['cantidad_disponible']  = (float)$item['cantidad_disponible'];
            $item['id_tipo']              = (int)$item['id_tipo'];
            $item['id_categoria']         = (int)$item['id_categoria'];
            $item['id_producto']          = (int)$item['id_producto'];
            $item['total_unidades']       = (int)$item['total_unidades'];
            // Solo exponemos id_unidad cuando es inequívoco (una sola unidad).
            $item['id_unidad'] = $item['total_unidades'] === 1 ? (int)$item['id_unidad_representativa'] : null;
            unset($item['id_unidad_representativa']);

            if ($item['total_unidades'] > 1) {
                $item['unidad']      = null;
                $item['abreviatura'] = null;
            }

            if ($item['proximo_vencimiento'] !== null) {
                $item['dias_para_vencer'] = (int)floor(
                    (strtotime($item['proximo_vencimiento']) - strtotime(date('Y-m-d'))) / 86400
                );
            }

            // Solo aplica a productos tipo Correlativo (1) — null en el resto.
            if ($item['proximo_correlativo_inicio'] !== null) {
                $item['proximo_correlativo_inicio'] = (int)$item['proximo_correlativo_inicio'];
                $item['proximo_correlativo_fin']     = (int)$item['proximo_correlativo_fin'];
            } else {
                $item['proximo_correlativo_inicio'] = null;
                $item['proximo_correlativo_fin']     = null;
            }
        }
        unset($item);

        return [
            'items'      => $items,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $porPagina,
        ];
    }

    private function _construirFiltros(int $idBodega, array $filtros): array
    {
        $condiciones = ['s.id_bodega = ?'];
        $params      = [$idBodega];

        $q = trim($filtros['q'] ?? '');
        if ($q !== '') {
            if (ctype_digit($q)) {
                $condiciones[] = "(p.nombre LIKE ? OR p.descripcion LIKE ? OR EXISTS (
                    SELECT 1 FROM bodega_inventario.lotes_correlativo lc
                    WHERE lc.id_bodega = s.id_bodega AND lc.id_producto = s.id_producto
                      AND ? BETWEEN lc.correlativo_inicial AND lc.correlativo_final
                ))";
                $params[] = "%{$q}%";
                $params[] = "%{$q}%";
                $params[] = (int)$q;
            } else {
                $condiciones[] = "(p.nombre LIKE ? OR p.descripcion LIKE ?)";
                $params[] = "%{$q}%";
                $params[] = "%{$q}%";
            }
        }

        if (!empty($filtros['id_tipo'])) {
            $condiciones[] = "p.id_tipo = ?";
            $params[] = (int)$filtros['id_tipo'];
        }

        if (!empty($filtros['id_categoria'])) {
            $condiciones[] = "p.id_categoria = ?";
            $params[] = (int)$filtros['id_categoria'];
        }

        // Nota: el filtro de unidad ahora se aplica a nivel de EXISTS, ya que
        // el listado principal está agrupado por producto.
        if (!empty($filtros['id_unidad'])) {
            $condiciones[] = "EXISTS (
                SELECT 1 FROM bodega_inventario.stock s2
                WHERE s2.id_bodega = s.id_bodega AND s2.id_producto = s.id_producto
                  AND s2.id_unidad = ?
            )";
            $params[] = (int)$filtros['id_unidad'];
        }

        $estado = $filtros['estado'] ?? '';
        switch ($estado) {
            case 'con_existencia':
                $condiciones[] = "EXISTS (
                    SELECT 1 FROM bodega_inventario.stock s3
                    WHERE s3.id_bodega = s.id_bodega AND s3.id_producto = s.id_producto
                      AND s3.cantidad_disponible > 0
                )";
                break;
            case 'sin_existencia':
                $condiciones[] = "NOT EXISTS (
                    SELECT 1 FROM bodega_inventario.stock s3
                    WHERE s3.id_bodega = s.id_bodega AND s3.id_producto = s.id_producto
                      AND s3.cantidad_total > 0
                )";
                break;
            case 'con_reserva':
                $condiciones[] = "EXISTS (
                    SELECT 1 FROM bodega_inventario.stock s3
                    WHERE s3.id_bodega = s.id_bodega AND s3.id_producto = s.id_producto
                      AND s3.cantidad_reservada > 0
                )";
                break;
            case 'por_vencer':
                $dias = (int)($filtros['dias_vencimiento'] ?? 30);
                $dias = in_array($dias, [30, 60, 90], true) ? $dias : 30;
                $condiciones[] = "EXISTS (
                    SELECT 1 FROM bodega_inventario.lotes_expiracion le
                    WHERE le.id_bodega = s.id_bodega AND le.id_producto = s.id_producto
                      AND le.cantidad_disponible > 0
                      AND le.fecha_expiracion <= DATE_ADD(CURDATE(), INTERVAL {$dias} DAY)
                )";
                break;
        }

        return [implode(' AND ', $condiciones), $params];
    }

    private function _resolverOrden(string $orden, string $direccion): string
    {
        $direccion = strtolower($direccion) === 'desc' ? 'DESC' : 'ASC';
        $columna = match ($orden) {
            'existencia'  => 'cantidad_total',
            'disponible'  => 'cantidad_disponible',
            'vencimiento' => 'proximo_vencimiento',
            default       => 'p.nombre',
        };
        if ($columna === 'proximo_vencimiento') {
            return "proximo_vencimiento IS NULL, proximo_vencimiento {$direccion}";
        }
        return "{$columna} {$direccion}";
    }

    // =========================================================================
    // CONTADORES (tarjetas superiores) — ahora por producto, no por fila
    // =========================================================================

    public function contadores(int $idBodega, int $diasVencimiento = 30): array
    {
        $dias = in_array($diasVencimiento, [30, 60, 90], true) ? $diasVencimiento : 30;

        $sql = "SELECT
                    COUNT(DISTINCT s.id_producto) AS total_productos,
                    COUNT(DISTINCT CASE WHEN s.cantidad_disponible > 0 THEN s.id_producto END) AS con_stock,
                    COUNT(DISTINCT CASE WHEN s.cantidad_reservada > 0 THEN s.id_producto END) AS en_reserva,
                    COUNT(DISTINCT CASE WHEN EXISTS (
                        SELECT 1 FROM bodega_inventario.lotes_expiracion le
                        WHERE le.id_bodega = s.id_bodega AND le.id_producto = s.id_producto
                          AND le.cantidad_disponible > 0
                          AND le.fecha_expiracion <= DATE_ADD(CURDATE(), INTERVAL {$dias} DAY)
                    ) THEN s.id_producto END) AS por_vencer
                FROM bodega_inventario.stock s
                WHERE s.id_bodega = ?";

        $stmt = $this->connect->prepare($sql);
        $stmt->execute([$idBodega]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_productos' => (int)($row['total_productos'] ?? 0),
            'con_stock'       => (int)($row['con_stock'] ?? 0),
            'en_reserva'      => (int)($row['en_reserva'] ?? 0),
            'por_vencer'      => (int)($row['por_vencer'] ?? 0),
        ];
    }

    // =========================================================================
    // NOTA: el selector de talla/unidad para Entrega Directa NO necesita un
    // método nuevo aquí — el front reutiliza directamente el endpoint ya
    // existente `inventarioApiClass::obtenerUnidadesProducto(id_producto,
    // id_bodega)`, que devuelve exactamente {tipo, unidades[],
    // lotes_correlativo?, cantidad_disponible?}. Mismo contrato, cero
    // duplicación.
    // =========================================================================
    // DETALLE EXPANDIBLE POR PRODUCTO (dentro del modal, por unidad ya elegida)
    // =========================================================================

    public function detalleProducto(int $idBodega, int $idProducto, int $idUnidad, int $idTipo): array
    {
        return match ($idTipo) {
            self::TIPO_CORRELATIVO => ['tipo' => self::TIPO_CORRELATIVO, 'lotes' => $this->_detalleCorrelativo($idBodega, $idProducto)],
            self::TIPO_EXPIRACION  => ['tipo' => self::TIPO_EXPIRACION,  'lotes' => $this->_detalleExpiracion($idBodega, $idProducto, $idUnidad)],
            default                => ['tipo' => self::TIPO_NORMAL,     'lotes' => $this->_detalleNormal($idBodega, $idProducto, $idUnidad)],
        };
    }

    private function _detalleCorrelativo(int $idBodega, int $idProducto): array
    {
        $stmt = $this->connect->prepare(
            "SELECT id, serie, resolucion, fecha_resolucion,
                    correlativo_inicial, correlativo_final, correlativo_siguiente,
                    cantidad_disponible, cantidad_reservada, created_at
             FROM   bodega_inventario.lotes_correlativo
             WHERE  id_bodega = ? AND id_producto = ? AND cantidad_disponible > 0
             ORDER  BY correlativo_inicial ASC"
        );
        $stmt->execute([$idBodega, $idProducto]);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lotes as &$l) {
            $l['rango_pendiente_inicial'] = (int)$l['correlativo_siguiente'];
            $l['rango_pendiente_final']   = (int)$l['correlativo_final'];
            $l['estado'] = ((int)$l['cantidad_reservada'] > 0) ? 'parcialmente_reservado' : 'disponible';
        }
        unset($l);

        return $lotes;
    }

    private function _detalleExpiracion(int $idBodega, int $idProducto, int $idUnidad): array
    {
        $stmt = $this->connect->prepare(
            "SELECT id, fecha_expiracion, cantidad_disponible, cantidad_reservada,
                    precio_unitario, created_at,
                    DATEDIFF(fecha_expiracion, CURDATE()) AS dias_restantes
             FROM   bodega_inventario.lotes_expiracion
             WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?
               AND  cantidad_disponible > 0
             ORDER  BY fecha_expiracion ASC"
        );
        $stmt->execute([$idBodega, $idProducto, $idUnidad]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function _detalleNormal(int $idBodega, int $idProducto, int $idUnidad): array
    {
        $stmt = $this->connect->prepare(
            "SELECT id, cantidad_disponible, fecha_ingreso, precio_unitario
             FROM   bodega_inventario.lotes_normal
             WHERE  id_bodega = ? AND id_producto = ? AND id_unidad = ?
               AND  cantidad_disponible > 0
             ORDER  BY fecha_ingreso ASC"
        );
        $stmt->execute([$idBodega, $idProducto, $idUnidad]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // EXPORTACIÓN — listado PLANO por lote (no agregado por producto)
    // Un producto con 3 lotes = 3 filas, cada una con sus propios datos.
    // Se usa para el reporte de Excel; la consulta principal (listar()) sigue
    // agregada por producto para la tabla en pantalla.
    // =========================================================================

    public function listarLotesParaExportar(int $idBodega, array $filtros): array
    {
        $filtrosBase = $filtros;
        unset($filtrosBase['pagina'], $filtrosBase['por_pagina']);
        [$where, $params] = $this->_construirFiltros($idBodega, $filtrosBase);

        // Productos que matchean los filtros (misma lógica que listar(), sin paginar)
        $sqlProductos = "SELECT DISTINCT s.id_producto, p.id_tipo
                          FROM bodega_inventario.stock s
                          INNER JOIN bodega_inventario.productos p ON p.id = s.id_producto
                          WHERE {$where}";
        $stmt = $this->connect->prepare($sqlProductos);
        $stmt->execute($params);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$productos) return [];

        $idsCorrelativo = array_column(array_filter($productos, fn($p) => (int)$p['id_tipo'] === self::TIPO_CORRELATIVO), 'id_producto');
        $idsExpiracion  = array_column(array_filter($productos, fn($p) => (int)$p['id_tipo'] === self::TIPO_EXPIRACION), 'id_producto');
        $idsNormal      = array_column(array_filter($productos, fn($p) => (int)$p['id_tipo'] === self::TIPO_NORMAL), 'id_producto');

        $filas = [];
        if ($idsCorrelativo) $filas = array_merge($filas, $this->_lotesCorrelativoParaExportar($idBodega, $idsCorrelativo));
        if ($idsExpiracion)  $filas = array_merge($filas, $this->_lotesExpiracionParaExportar($idBodega, $idsExpiracion));
        if ($idsNormal)      $filas = array_merge($filas, $this->_lotesNormalParaExportar($idBodega, $idsNormal));

        return $filas;
    }

    private function _placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    private function _lotesCorrelativoParaExportar(int $idBodega, array $idsProducto): array
    {
        $ph = $this->_placeholders($idsProducto);
        $sql = "SELECT
                    p.nombre AS producto, cp.nombre AS categoria, 'Correlativo' AS tipo_producto,
                    lc.serie, lc.resolucion, lc.fecha_resolucion,
                    lc.correlativo_inicial, lc.correlativo_final, lc.correlativo_siguiente,
                    lc.cantidad_disponible, lc.cantidad_reservada, lc.created_at AS fecha_lote
                FROM bodega_inventario.lotes_correlativo lc
                INNER JOIN bodega_inventario.productos p ON p.id = lc.id_producto
                INNER JOIN bodega_inventario.categorias_producto cp ON cp.id = p.id_categoria
                WHERE lc.id_bodega = ? AND lc.id_producto IN ({$ph}) AND lc.cantidad_disponible > 0
                ORDER BY p.nombre, lc.correlativo_inicial";
        $stmt = $this->connect->prepare($sql);
        $stmt->execute(array_merge([$idBodega], $idsProducto));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['unidad']         = null;
            $r['rango_lote']     = "{$r['correlativo_inicial']}–{$r['correlativo_final']}";
            $r['rango_pendiente']= "{$r['correlativo_siguiente']}–{$r['correlativo_final']}";
            $r['cantidad']       = (float)$r['cantidad_disponible'];
            $r['cantidad_reservada'] = (float)$r['cantidad_reservada'];
            $r['fecha_referencia'] = $r['fecha_resolucion'];
            $r['precio_unitario']  = null;
        }
        unset($r);

        return $rows;
    }

    private function _lotesExpiracionParaExportar(int $idBodega, array $idsProducto): array
    {
        $ph = $this->_placeholders($idsProducto);
        $sql = "SELECT
                    p.nombre AS producto, cp.nombre AS categoria, 'Fecha de expiración' AS tipo_producto,
                    um.nombre AS unidad,
                    le.fecha_expiracion, le.cantidad_disponible, le.cantidad_reservada,
                    le.precio_unitario, le.created_at AS fecha_lote,
                    DATEDIFF(le.fecha_expiracion, CURDATE()) AS dias_restantes
                FROM bodega_inventario.lotes_expiracion le
                INNER JOIN bodega_inventario.productos p ON p.id = le.id_producto
                INNER JOIN bodega_inventario.categorias_producto cp ON cp.id = p.id_categoria
                INNER JOIN bodega_inventario.unidades_medida um ON um.id = le.id_unidad
                WHERE le.id_bodega = ? AND le.id_producto IN ({$ph}) AND le.cantidad_disponible > 0
                ORDER BY p.nombre, le.fecha_expiracion";
        $stmt = $this->connect->prepare($sql);
        $stmt->execute(array_merge([$idBodega], $idsProducto));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['rango_lote']         = null;
            $r['rango_pendiente']    = null;
            $r['cantidad']           = (float)$r['cantidad_disponible'];
            $r['cantidad_reservada'] = (float)$r['cantidad_reservada'];
            $r['fecha_referencia']   = $r['fecha_expiracion'];
        }
        unset($r);

        return $rows;
    }

    private function _lotesNormalParaExportar(int $idBodega, array $idsProducto): array
    {
        $ph = $this->_placeholders($idsProducto);
        $sql = "SELECT
                    p.nombre AS producto, cp.nombre AS categoria, 'Normal' AS tipo_producto,
                    um.nombre AS unidad,
                    ln.fecha_ingreso, ln.cantidad_disponible, ln.precio_unitario,
                    ln.fecha_ingreso AS fecha_lote
                FROM bodega_inventario.lotes_normal ln
                INNER JOIN bodega_inventario.productos p ON p.id = ln.id_producto
                INNER JOIN bodega_inventario.categorias_producto cp ON cp.id = p.id_categoria
                INNER JOIN bodega_inventario.unidades_medida um ON um.id = ln.id_unidad
                WHERE ln.id_bodega = ? AND ln.id_producto IN ({$ph}) AND ln.cantidad_disponible > 0
                ORDER BY p.nombre, ln.fecha_ingreso";
        $stmt = $this->connect->prepare($sql);
        $stmt->execute(array_merge([$idBodega], $idsProducto));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['rango_lote']          = null;
            $r['rango_pendiente']     = null;
            $r['cantidad']            = (float)$r['cantidad_disponible'];
            $r['cantidad_reservada']  = 0.0; // lotes_normal no reserva a nivel de lote
            $r['fecha_referencia']    = $r['fecha_ingreso'];
        }
        unset($r);

        return $rows;
    }
}