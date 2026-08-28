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
 * FLUJO 1 — Encargado de bodega de agencia -> Administrador de Bodegas.
 * Solo valida ORIGEN y ACCESO; el ciclo de vida vive en CompraService.
 */
final class CompraAgenciaService
{
    public function __construct(
        private PDO $connect,
        private CompraRepository $repo,
        private CompraService $compraService,
        private BodegaHelper $bodegaHelper,
    ) {
    }

    /** @param array<array{id_producto:int,id_unidad:int,cantidad:float,justificacion?:?string}> $lineas */
    public function crearSolicitud(int $idBodega, string $idUsuario, int $idAgenciaSesion, array $lineas): int
    {
        $bodega = $this->validarBodegaYEncargado($idBodega, $idAgenciaSesion);
        $this->validarLineas($lineas);

        return $this->compraService->crear(
            idBodega: $idBodega,
            tipoOrigen: TipoOrigenCompra::SOLICITUD_AGENCIA,
            estadoInicial: EstadoCompra::SOLICITADA,
            lineas: $lineas,
            idUsuarioSolicitante: $idUsuario,
        );
    }

    /**
     * Edición completa de una solicitud propia — solo mientras sigue
     * SOLICITADA (aún no fue tocada por el Administrador de Bodegas). La
     * pertenencia de la compra y el estado los valida CompraService; aquí
     * solo se revalida que el nuevo detalle sea consistente (mismas reglas
     * que al crear).
     *
     * @param array<array{id_producto:int,id_unidad:int,cantidad:float,justificacion?:?string}> $lineas
     */
    public function editarSolicitud(int $idCompra, string $idUsuario, array $lineas): void
    {
        $this->validarLineas($lineas);
        $this->compraService->editarLineas($idCompra, $idUsuario, $lineas);
    }

    /** Detalle completo (cabecera + líneas) para el modal de detalle del Administrador y del encargado. */
    public function obtenerDetalle(int $idCompra): object
    {
        return $this->compraService->obtenerDetalle($idCompra);
    }

    public function gestionarSolicitud(
        int $idCompra,
        bool $aprueba,
        string $idUsuarioGestor,
        ?int $idPuestoSesion,
        string $comentario,
        array $lineasAjuste = [],
    ): void {
        if (!\App\inventarioApi\Helpers\RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede gestionar solicitudes de compra de agencia');
        }

        $this->compraService->decidirSolicitud($idCompra, $aprueba, $idUsuarioGestor, $comentario, $lineasAjuste);
    }

    public function listarBandejaAdmin(string $busqueda, ?int $idEstado, int $pagina, int $porPagina): array
    {
        return $this->listar('b.id_tipo = ' . TipoBodega::AGENCIA->value, [], $busqueda, $idEstado, $pagina, $porPagina);
    }

    public function listarMisSolicitudes(string $idUsuario, string $busqueda, ?int $idEstado, int $pagina, int $porPagina): array
    {
        return $this->listar(
            'c.id_usuario_solicitante = ? AND b.id_tipo = ' . TipoBodega::AGENCIA->value,
            [$idUsuario], $busqueda, $idEstado, $pagina, $porPagina
        );
    }

    // -----------------------------------------------------------------

    private function validarBodegaYEncargado(int $idBodega, int $idAgenciaSesion): object
    {
        $bodega = $this->repo->obtenerBodegaActiva($idBodega);

        if (!$bodega) {
            throw new Exception('La bodega destino seleccionada no existe o se encuentra inactiva');
        }
        if (TipoBodega::from((int) $bodega->id_tipo) !== TipoBodega::AGENCIA) {
            throw new Exception('Esta operación es solo para bodegas de agencia; use el flujo de área para bodegas de área');
        }

        $idBodegaAgenciaSesion = $this->bodegaHelper->obtenerBodegaAgencia();
        if ($idBodegaAgenciaSesion === null || $idBodegaAgenciaSesion !== $idBodega) {
            throw new Exception('Solo el encargado de esta bodega de agencia puede solicitar compras para ella');
        }

        return $bodega;
    }

    /** @param array<array{id_producto:int,id_unidad:int,cantidad:float,justificacion?:?string}> $lineas */
    private function validarLineas(array $lineas): void
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