<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoBodega;
use App\inventarioApi\Enums\TipoOrigenCompra;
use App\inventarioApi\Helpers\BodegaHelper;
use App\inventarioApi\Repositories\CompraRepository;
use Exception;
use PDO;

/**
 * FLUJO 2 — Cualquier usuario -> Encargado de bodega de área.
 * La validación de acceso es dato-a-dato (matriz por bodega+puesto+agencia
 * +producto), a diferencia del rol genérico del Flujo 1.
 */
final class CompraAreaService
{
    public function __construct(
        private PDO $connect,
        private CompraRepository $repo,
        private CompraService $compraService,
        private BodegaHelper $bodegaHelper,
    ) {
    }

    // =====================================================================
    // Paso 1 — áreas disponibles para el usuario
    // =====================================================================

    public function listarAreasDisponibles(?int $idPuestoSesion, int $idAgenciaSesion): array
    {
        $stmt = $this->connect->prepare(
            'SELECT id, nombre, restriccion_acceso_activa
             FROM bodega_inventario.bodegas
             WHERE id_tipo = ? AND activo = 1
             ORDER BY nombre ASC'
        );
        $stmt->execute([TipoBodega::AREA->value]);

        return array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_OBJ),
            fn ($area) => !(bool) $area->restriccion_acceso_activa
                || $this->bodegaHelper->tieneAccesoMatriz((int) $area->id, $idPuestoSesion, $idAgenciaSesion)
        ));
    }

    // =====================================================================
    // Paso 2 — catálogo filtrado por matriz de acceso
    // =====================================================================

    public function productosPermitidosParaSolicitud(int $idBodega, ?int $idPuestoSesion, int $idAgenciaSesion): array
    {
        $bodega = $this->obtenerBodegaAreaOFallar($idBodega);
        $restringida = (bool) $bodega->restriccion_acceso_activa;

        if ($restringida && !$this->bodegaHelper->tieneAccesoMatriz($idBodega, $idPuestoSesion, $idAgenciaSesion)) {
            throw new Exception('Acceso denegado: sus credenciales o puesto no figuran con accesos vigentes en la matriz de esta bodega de área');
        }

        $idsPermitidos = $this->productosPermitidosPorMatriz($idBodega, $idPuestoSesion, $idAgenciaSesion, $restringida);

        return ['restringida' => $restringida, 'productos' => $this->obtenerCatalogoProductos($idsPermitidos)];
    }

    // =====================================================================
    // Creación
    // =====================================================================

    /** @param array<array{id_producto:int,id_unidad:int,cantidad:float,justificacion?:?string}> $lineas */
    public function crearSolicitud(int $idBodega, string $idUsuario, ?int $idPuestoSesion, int $idAgenciaSesion, array $lineas): int
    {
        $bodega = $this->obtenerBodegaAreaOFallar($idBodega);
        $restringida = (bool) $bodega->restriccion_acceso_activa;

        if ($restringida && !$this->bodegaHelper->tieneAccesoMatriz($idBodega, $idPuestoSesion, $idAgenciaSesion)) {
            throw new Exception('Acceso denegado: sus credenciales o puesto no figuran con accesos vigentes en la matriz de esta bodega de área');
        }

        $productosPermitidos = $this->productosPermitidosPorMatriz($idBodega, $idPuestoSesion, $idAgenciaSesion, $restringida);
        $this->validarLineas($lineas, $productosPermitidos);

        return $this->compraService->crear(
            idBodega: $idBodega,
            tipoOrigen: TipoOrigenCompra::SOLICITUD_AREA,
            estadoInicial: EstadoCompra::SOLICITADA,
            lineas: $lineas,
            idUsuarioSolicitante: $idUsuario,
        );
    }

    // =====================================================================
    // Edición — solo mientras la compra sigue SOLICITADA y solo su dueño
    // =====================================================================
    //
    // A diferencia de Agencia, aquí la bodega SÍ importa para revalidar: la
    // matriz de acceso pudo haber cambiado desde que se creó la solicitud
    // (o el usuario intenta colar un producto fuera de su matriz al editar).
    // Por eso se vuelve a resolver la matriz igual que en crearSolicitud,
    // usando la bodega que ya tiene la compra (no se puede cambiar de
    // bodega al editar).

    /** @param array<array{id_producto:int,id_unidad:int,cantidad:float,justificacion?:?string}> $lineas */
    public function editarSolicitud(int $idCompra, string $idUsuario, ?int $idPuestoSesion, int $idAgenciaSesion, array $lineas): void
    {
        // obtenerDetalle no valida dueño/estado — eso lo hace compraService->editarLineas()
        // más abajo. Aquí solo lo usamos para conocer la bodega y poder revalidar la matriz.
        $compraActual = $this->compraService->obtenerDetalle($idCompra);
        $idBodega     = (int) $compraActual->id_bodega;

        $bodega = $this->obtenerBodegaAreaOFallar($idBodega);
        $restringida = (bool) $bodega->restriccion_acceso_activa;

        if ($restringida && !$this->bodegaHelper->tieneAccesoMatriz($idBodega, $idPuestoSesion, $idAgenciaSesion)) {
            throw new Exception('Acceso denegado: sus credenciales o puesto no figuran con accesos vigentes en la matriz de esta bodega de área');
        }

        $productosPermitidos = $this->productosPermitidosPorMatriz($idBodega, $idPuestoSesion, $idAgenciaSesion, $restringida);
        $this->validarLineas($lineas, $productosPermitidos);

        $this->compraService->editarLineas($idCompra, $idUsuario, $lineas);
    }

    // =====================================================================
    // Detalle — cabecera + líneas completas (modales de detalle/gestión)
    // =====================================================================

    public function obtenerDetalle(int $idCompra): object
    {
        return $this->compraService->obtenerDetalle($idCompra);
    }

    public function gestionarSolicitud(int $idCompra, bool $aprueba, string $idUsuarioGestor, string $comentario, array $lineasAjuste = []): void
    {
        // La compra ya trae id_bodega; basta con validar que el gestor sea
        // el encargado exacto de esa bodega de área (relación dato-a-dato).
        $idBodegaEncargado = $this->bodegaHelper->obtenerBodegaDelEncargado();

        if ($idBodegaEncargado === null) {
            throw new Exception('Solo el encargado asignado a una bodega de área puede gestionar solicitudes de compra');
        }

        $this->compraService->decidirSolicitud($idCompra, $aprueba, $idUsuarioGestor, $comentario, $lineasAjuste);
    }

    public function listarBandejaArea(int $idBodegaEncargado, string $busqueda, ?int $idEstado, int $pagina, int $porPagina): array
    {
        return $this->listar('c.id_bodega = ?', [$idBodegaEncargado], $busqueda, $idEstado, $pagina, $porPagina);
    }

    /**
     * "Mis solicitudes" del usuario que las creó. $idBodega es OPCIONAL: si
     * viene, se acota a una sola área (caso normal — el usuario entra a una
     * tarjeta de área y ve solo lo que pidió ahí); si no viene, trae sus
     * solicitudes de TODAS las áreas de una vez.
     */
    public function listarMisSolicitudes(string $idUsuario, ?int $idBodega, string $busqueda, ?int $idEstado, int $pagina, int $porPagina): array
    {
        $where  = 'c.id_usuario_solicitante = ? AND b.id_tipo = ' . TipoBodega::AREA->value;
        $params = [$idUsuario];

        if ($idBodega !== null) {
            $where   .= ' AND c.id_bodega = ?';
            $params[] = $idBodega;
        }

        return $this->listar($where, $params, $busqueda, $idEstado, $pagina, $porPagina);
    }

    // -----------------------------------------------------------------

    private function obtenerBodegaAreaOFallar(int $idBodega): object
    {
        $bodega = $this->repo->obtenerBodegaActiva($idBodega);

        if (!$bodega) {
            throw new Exception('La bodega de área seleccionada no existe o se encuentra inactiva');
        }
        if (TipoBodega::from((int) $bodega->id_tipo) !== TipoBodega::AREA) {
            throw new Exception('Esta operación es solo para bodegas de área; use el flujo de agencia para bodegas de agencia');
        }

        return $bodega;
    }

    /** @return array<int>|null null = sin restricción (catálogo completo) */
    private function productosPermitidosPorMatriz(int $idBodega, ?int $idPuestoSesion, int $idAgenciaSesion, bool $restringida): ?array
    {
        if (!$restringida) {
            return null;
        }

        $stmt = $this->connect->prepare(
            'SELECT DISTINCT map.id_producto
             FROM bodega_inventario.matriz_acceso ma
             INNER JOIN bodega_inventario.matriz_acceso_productos map ON map.id_matriz = ma.id
             WHERE ma.id_bodega = ? AND ma.id_puesto = ? AND ma.id_agencia = ? AND ma.activo = 1'
        );
        $stmt->execute([$idBodega, $idPuestoSesion, $idAgenciaSesion]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<int>|null $idsPermitidos */
    private function obtenerCatalogoProductos(?array $idsPermitidos): array
    {
        $where  = 'p.activo = 1';
        $params = [];

        if ($idsPermitidos !== null) {
            if (empty($idsPermitidos)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($idsPermitidos), '?'));
            $where       .= " AND p.id IN ({$placeholders})";
            $params        = $idsPermitidos;
        }

        $stmt = $this->connect->prepare(
            "SELECT p.id, p.nombre, p.id_tipo, tp.nombre AS tipo,
                    pu.id_unidad, u.nombre AS unidad_nombre, u.abreviatura, pu.es_default
             FROM bodega_inventario.productos p
             INNER JOIN bodega_inventario.tipos_producto tp ON tp.id = p.id_tipo
             INNER JOIN bodega_inventario.productos_unidades pu ON pu.id_producto = p.id AND pu.activo = 1
             INNER JOIN bodega_inventario.unidades_medida u ON u.id = pu.id_unidad
             WHERE {$where}
             ORDER BY p.nombre ASC"
        );
        $stmt->execute($params);

        $porProducto = [];
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $f) {
            $id = (int) $f->id;
            $porProducto[$id] ??= [
                'id' => $id, 'nombre' => $f->nombre, 'id_tipo' => (int) $f->id_tipo,
                'tipo' => $f->tipo, 'unidades' => [],
            ];
            $porProducto[$id]['unidades'][] = [
                'id' => (int) $f->id_unidad, 'nombre' => $f->unidad_nombre,
                'abreviatura' => $f->abreviatura, 'es_default' => (bool) $f->es_default,
            ];
        }

        return array_values($porProducto);
    }

    /** @param array<int>|null $productosPermitidos */
    private function validarLineas(array $lineas, ?array $productosPermitidos): void
    {
        if (empty($lineas)) {
            throw new Exception('Debe indicar al menos una línea de producto');
        }

        $stmt = $this->connect->prepare(
            'SELECT COUNT(*) FROM bodega_inventario.productos_unidades WHERE id_producto = ? AND id_unidad = ? AND activo = 1'
        );

        foreach ($lineas as $i => $linea) {
            $idProducto = (int) ($linea['id_producto'] ?? 0);
            $idUnidad   = (int) ($linea['id_unidad'] ?? 0);
            $cantidad   = (float) ($linea['cantidad'] ?? 0);

            if ($idProducto < 1 || $idUnidad < 1 || $cantidad <= 0) {
                throw new Exception('Error de consistencia: la línea #' . ($i + 1) . ' tiene campos obligatorios vacíos o una cantidad inválida');
            }
            if ($productosPermitidos !== null && !in_array($idProducto, $productosPermitidos, true)) {
                throw new Exception('La línea #' . ($i + 1) . ' tiene un producto fuera de la matriz de acceso de esta bodega');
            }

            $stmt->execute([$idProducto, $idUnidad]);
            if ((int) $stmt->fetchColumn() === 0) {
                throw new Exception('La línea #' . ($i + 1) . ' tiene una combinación de producto/unidad no válida o inactiva');
            }
        }
    }

    private function listar(string $whereBase, array $paramsBase, string $busqueda, ?int $idEstado, int $pagina, int $porPagina): array
    {
        $where  = $whereBase;
        $params = $paramsBase;

        if ($idEstado !== null) {
            $where   .= ' AND c.id_estado = ?';
            $params[] = $idEstado;
        }
        if ($busqueda !== '') {
            $where   .= ' AND b.nombre LIKE ?';
            $params[] = "%{$busqueda}%";
        }

        $resultado = $this->repo->listar($where, $params, $pagina, $porPagina);

        $ids       = array_map(static fn ($c) => (int) $c->id, $resultado['compras']);
        $lineasPor = $this->repo->obtenerLineasResumenPorCompras($ids);

        foreach ($resultado['compras'] as $c) {
            $c->estado       = EstadoCompra::from((int) $c->id_estado)->nombre();
            $c->lineas       = $lineasPor[(int) $c->id] ?? [];
            $c->total_lineas = count($c->lineas);
        }

        return $resultado;
    }
}