<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoBodega;
use App\inventarioApi\Enums\TipoOrigenCompra;
use App\inventarioApi\Helpers\BodegaHelper;
use App\inventarioApi\Helpers\RolCompraHelper;
use App\inventarioApi\Repositories\CompraRepository;
use Exception;
use PDO;

/**
 * FLUJO 4 — Compra extraordinaria directa, sin solicitud previa. Dos
 * caminos con reglas distintas — no es un solo flujo con una bandera:
 *
 *   - crearOrdenAgencia() — Administrador de Bodegas, para CUALQUIER
 *     bodega de Agencia. Siempre nace en REQUIERE_AUTORIZACION: pasa por
 *     Gerencia/Financiero antes de llegar a mesa de trabajo.
 *
 *   - crearOrdenArea() — Encargado de Área, únicamente para SU PROPIA
 *     bodega (resuelta por sesión, sin selector). Nace directo en
 *     APROBADA, sin autorización — es el mismo que la genera y el mismo
 *     que la va a trabajar en su propia mesa de trabajo, así que no tiene
 *     sentido pedirle permiso a nadie más.
 */
final class CompraExtraordinariaService
{
    public function __construct(
        private PDO $connect,
        private CompraRepository $repo,
        private CompraService $compraService,
        private BodegaHelper $bodegaHelper,
    ) {
    }

    // =====================================================================
    // Administrador de Bodegas — cualquier bodega de Agencia
    // =====================================================================

    /** @param array<array{id_producto:int,id_unidad:int,cantidad:float}> $lineas */
    public function crearOrdenAgencia(int $idBodega, string $idUsuarioAdmin, ?int $idPuestoSesion, array $lineas): int
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede crear compras extraordinarias de agencia');
        }

        $bodega = $this->repo->obtenerBodegaActiva($idBodega);
        if (!$bodega) {
            throw new Exception('La bodega destino seleccionada no existe o se encuentra inactiva');
        }
        if (TipoBodega::from((int) $bodega->id_tipo) !== TipoBodega::AGENCIA) {
            throw new Exception('Esta acción es solo para bodegas de agencia — el encargado de área genera las suyas desde su propia mesa de trabajo, sin autorización');
        }

        $this->validarLineas($lineas);

        return $this->compraService->crear(
            idBodega: $idBodega,
            tipoOrigen: TipoOrigenCompra::EXTRAORDINARIA,
            estadoInicial: EstadoCompra::REQUIERE_AUTORIZACION,
            lineas: $lineas,
            idUsuarioAdmin: $idUsuarioAdmin,
            requiereAutorizacion: true,
        );
    }

    // =====================================================================
    // Encargado de Área — únicamente su propia bodega, sin autorización
    // =====================================================================

    /** @param array<array{id_producto:int,id_unidad:int,cantidad:float}> $lineas */
    public function crearOrdenArea(string $idUsuarioSesion, array $lineas): int
    {
        $idBodega = $this->bodegaHelper->obtenerBodegaDelEncargado();
        if ($idBodega === null) {
            throw new Exception('Solo el encargado asignado a una bodega de área puede generar sus propias compras extraordinarias');
        }

        $this->validarLineas($lineas);

        return $this->compraService->crear(
            idBodega: $idBodega,
            tipoOrigen: TipoOrigenCompra::EXTRAORDINARIA,
            estadoInicial: EstadoCompra::APROBADA,
            lineas: $lineas,
            // El encargado es tanto quien la solicita como quien la gestiona
            // (genera y trabaja su propia compra) — se guarda en ambos campos.
            idUsuarioSolicitante: $idUsuarioSesion,
            idUsuarioAdmin: $idUsuarioSesion,
            requiereAutorizacion: false,
        );
    }

    // -----------------------------------------------------------------

    private function validarLineas(array $lineas): void
    {
        if (empty($lineas)) {
            throw new Exception('Debe indicar al menos una línea de producto para la compra extraordinaria');
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
}