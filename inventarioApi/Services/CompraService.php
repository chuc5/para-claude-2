<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoBodega;
use App\inventarioApi\Enums\TipoOrigenCompra;
use App\inventarioApi\Repositories\CompraRepository;
use Exception;

/**
 * CompraService
 *
 * Máquina de estados ÚNICA para toda compra, sin importar su origen
 * (Agencia, Área, Trimestral, Extraordinaria). Reemplaza:
 *   - SolicitudCompraHelper (crear + decidir)
 *   - OrdenCompraHelper     (ajustar/autorizar/comprar/enviar/registrar/cancelar)
 *   - OrdenCompraAreaHelper (auto-envío para bodega de área)
 *   - CompraHelper          (duplicado sin uso real, eliminado)
 *
 * El auto-envío para bodegas de Área ya no es una subclase: es una regla
 * de negocio explícita (`tipoBodegaGestion === AREA`) evaluada dentro de
 * marcarLineaComprada(). Composición sobre herencia: un solo camino de
 * código, más fácil de auditar y de testear.
 *
 * Convención: no abre ni hace commit/rollback de transacciones — eso es
 * responsabilidad del controlador que invoca.
 */
final class CompraService
{
    public function __construct(private CompraRepository $repo)
    {
    }

    // =====================================================================
    // CREACIÓN
    // =====================================================================

    /**
     * @param array<array{id_producto:int,id_unidad:int,cantidad:float,justificacion?:?string}> $lineas
     */
    public function crear(
        int $idBodega,
        TipoOrigenCompra $tipoOrigen,
        EstadoCompra $estadoInicial,
        array $lineas,
        ?string $idUsuarioSolicitante = null,
        ?string $idUsuarioAdmin = null,
        bool $requiereAutorizacion = false,
    ): int {
        if (empty($lineas)) {
            throw new Exception('Debe indicar al menos una línea de producto');
        }

        $idCompra = $this->repo->crearCompra([
            'id_bodega'               => $idBodega,
            'id_tipo_origen'          => $tipoOrigen->value,
            'id_estado'               => $estadoInicial->value,
            'id_usuario_solicitante'  => $idUsuarioSolicitante,
            'id_usuario_admin'        => $idUsuarioAdmin,
            'requiere_autorizacion'   => $requiereAutorizacion ? 1 : 0,
        ]);

        $this->repo->agregarLineas($idCompra, array_map(
            static fn (array $l) => [
                'id_producto'         => $l['id_producto'],
                'id_unidad'           => $l['id_unidad'],
                'id_bodega_destino'   => $idBodega,
                'cantidad_solicitada' => $l['cantidad'],
                'justificacion'       => $l['justificacion'] ?? null,
                // Opcionales — solo aplican si el producto es de control Correlativo;
                // si el usuario no los llenó, quedan NULL y se completan al recibir el lote.
                'serie'               => $l['serie'] ?? null,
                'resolucion'          => $l['resolucion'] ?? null,
                'fecha_resolucion'    => $l['fecha_resolucion'] ?? null,
                'correlativo_inicial' => $l['correlativo_inicial'] ?? null,
                'correlativo_final'   => $l['correlativo_final'] ?? null,
            ],
            $lineas
        ));

        return $idCompra;
    }

    // =====================================================================
    // EDICIÓN — solo mientras la compra sigue SOLICITADA y solo su dueño
    // =====================================================================

    /**
     * Reemplaza por completo el detalle de una compra que el solicitante
     * quiere corregir antes de que el gestor la revise (agrega/quita/cambia
     * líneas). Una vez aprobada, rechazada o en cualquier otro estado, ya
     * no se puede tocar: para eso existe la mesa de trabajo (ajuste
     * controlado, con reglas de alza/baja) o cancelarSolicitud().
     *
     * @param array<array{id_producto:int,id_unidad:int,cantidad:float,justificacion?:?string}> $lineas
     */
    public function editarLineas(int $idCompra, string $idUsuarioSesion, array $lineas): void
    {
        if (empty($lineas)) {
            throw new Exception('Debe indicar al menos una línea de producto');
        }

        $compra = $this->obtenerCompraOFallar($idCompra);

        if ($compra->id_usuario_solicitante !== $idUsuarioSesion) {
            throw new Exception('Solo el usuario que creó la solicitud puede editarla');
        }

        $this->exigirEstado($compra, EstadoCompra::SOLICITADA);

        $this->repo->eliminarLineas($idCompra);
        $this->repo->agregarLineas($idCompra, array_map(
            static fn (array $l) => [
                'id_producto'         => $l['id_producto'],
                'id_unidad'           => $l['id_unidad'],
                'id_bodega_destino'   => $compra->id_bodega,
                'cantidad_solicitada' => $l['cantidad'],
                'justificacion'       => $l['justificacion'] ?? null,
                'serie'               => $l['serie'] ?? null,
                'resolucion'          => $l['resolucion'] ?? null,
                'fecha_resolucion'    => $l['fecha_resolucion'] ?? null,
                'correlativo_inicial' => $l['correlativo_inicial'] ?? null,
                'correlativo_final'   => $l['correlativo_final'] ?? null,
            ],
            $lineas
        ));
    }

    // =====================================================================
    // DETALLE — cabecera + líneas completas (modales de detalle/gestión)
    // =====================================================================

    public function obtenerDetalle(int $idCompra): object
    {
        $compra = $this->repo->obtenerCabecera($idCompra);
        if (!$compra) {
            throw new Exception('La compra indicada no existe');
        }

        $compra->estado = EstadoCompra::from((int) $compra->id_estado)->nombre();
        $compra->lineas = $this->repo->obtenerLineas($idCompra);

        return $compra;
    }

    // =====================================================================
    // DECISIÓN DEL GESTOR (aprobar / rechazar) — flujos Agencia y Área
    // =====================================================================
    /** json_decode entrega cada línea del payload como stdClass, no como array. */
    private function normalizarLinea($linea): array
    {
        return is_array($linea) ? $linea : (array) $linea;
    }
    /**
     * @param array<array{id_linea:int,cantidad_ajustada:float,serie?:?string,resolucion?:?string,fecha_resolucion?:?string,correlativo_inicial?:?int,correlativo_final?:?int}> $lineasAjuste Solo líneas que el gestor decide bajar/ajustar
     */
    public function decidirSolicitud(int $idCompra, bool $aprueba, string $idUsuarioGestor, string $comentario, array $lineasAjuste = []): void
    {
        $compra = $this->obtenerCompraOFallar($idCompra);
        $this->exigirEstado($compra, EstadoCompra::SOLICITADA);

        if (!$aprueba) {
            $this->repo->registrarGestion($idCompra, EstadoCompra::RECHAZADA, $idUsuarioGestor, $comentario);
            return;
        }

        foreach ($lineasAjuste as $i => $linea) {
            $linea    = $this->normalizarLinea($linea);
            $idLinea  = (int) ($linea['id_linea'] ?? 0);
            $cantidad = (float) ($linea['cantidad_ajustada'] ?? 0);

            if ($idLinea < 1 || $cantidad <= 0) {
                throw new Exception('Error de consistencia: la línea #' . ($i + 1) . ' tiene datos de ajuste inválidos');
            }

            $this->repo->ajustarCantidadLinea($idLinea, $cantidad);

            // El correlativo es opcional: el gestor solo lo toca si el producto
            // es de control Correlativo y el frontend lo incluyó en el payload
            // (para que el rango declarado concuerde con la cantidad aprobada).
            $tocaCorrelativo = array_key_exists('serie', $linea)
                || array_key_exists('resolucion', $linea)
                || array_key_exists('correlativo_inicial', $linea)
                || array_key_exists('correlativo_final', $linea);

            if ($tocaCorrelativo) {
                $this->repo->actualizarCorrelativoLinea(
                    $idLinea,
                    $this->strOVacioANull($linea['serie'] ?? null),
                    $this->strOVacioANull($linea['resolucion'] ?? null),
                    $this->strOVacioANull($linea['fecha_resolucion'] ?? null),
                    $this->intOVacioANull($linea['correlativo_inicial'] ?? null),
                    $this->intOVacioANull($linea['correlativo_final'] ?? null),
                );
            }
        }

        $this->repo->registrarGestion($idCompra, EstadoCompra::APROBADA, $idUsuarioGestor, $comentario);
    }

    private function strOVacioANull($valor): ?string
    {
        $valor = is_string($valor) ? trim($valor) : $valor;
        return ($valor === null || $valor === '') ? null : (string) $valor;
    }

    private function intOVacioANull($valor): ?int
    {
        return ($valor === null || $valor === '') ? null : (int) $valor;
    }

    public function cancelarSolicitud(int $idCompra, string $idUsuarioSesion): void
    {
        $compra = $this->obtenerCompraOFallar($idCompra);

        if ($compra->id_usuario_solicitante !== $idUsuarioSesion) {
            throw new Exception('Solo el usuario que creó la solicitud puede cancelarla');
        }

        $this->exigirEstado($compra, EstadoCompra::SOLICITADA);
        $this->repo->registrarGestion($idCompra, EstadoCompra::CANCELADA, $idUsuarioSesion, 'Cancelada por el solicitante');
    }

    // =====================================================================
    // AUTORIZACIÓN DE GERENCIA/FINANCIERO
    // =====================================================================

    public function autorizar(int $idCompra, bool $autoriza, string $idUsuarioAutorizador, string $comentario): void
    {
        $compra = $this->obtenerCompraOFallar($idCompra);
        $this->exigirEstado($compra, EstadoCompra::REQUIERE_AUTORIZACION);

        $nuevoEstado = $autoriza ? EstadoCompra::APROBADA : EstadoCompra::RECHAZADA;
        $this->repo->registrarAutorizacion($idCompra, $nuevoEstado, $idUsuarioAutorizador, $comentario);
    }

    // =====================================================================
    // MESA DE TRABAJO — ajuste de cantidad
    // =====================================================================

    /**
     * @param bool $omitirAutorizacion Si es true, un alza NO recalcula
     *   "requiere autorización" — la usa la mesa de trabajo de Área, donde
     *   subir cantidad es una decisión totalmente del encargado, sin pasar
     *   por Gerencia/Financiero. La mesa de Agencia nunca manda true aquí
     *   (de todos modos nunca alza, porque Agencia no lo permite).
     */
    public function ajustarCantidadLinea(int $idCompra, int $idLinea, float $cantidadAjustada, bool $omitirAutorizacion = false): void
    {
        $compra = $this->obtenerCompraOFallar($idCompra);
        $this->exigirEstado($compra, EstadoCompra::APROBADA);

        $linea = $this->obtenerLineaOFallar($idCompra, $idLinea);

        if ((bool) $linea->comprado_con_precio) {
            throw new Exception('No se puede ajustar la cantidad de una línea que ya fue comprada');
        }

        if ($cantidadAjustada <= 0) {
            throw new Exception('La cantidad ajustada debe ser mayor a cero');
        }

        $tipoBodegaDestino = TipoBodega::from((int) $linea->tipo_bodega_destino);
        $esAlza = $cantidadAjustada > (float) $linea->cantidad_solicitada;

        if ($esAlza && !$tipoBodegaDestino->permiteAlza()) {
            throw new Exception('Para bodegas de agencia solo se permite ajustar la cantidad a la baja, no incrementarla');
        }

        $this->repo->ajustarCantidadLinea($idLinea, $cantidadAjustada);

        if (!$omitirAutorizacion) {
            $this->recalcularRequiereAutorizacion($idCompra);
        }
    }

    private function recalcularRequiereAutorizacion(int $idCompra): void
    {
        $compra = $this->obtenerCompraOFallar($idCompra);

        if (EstadoCompra::from((int) $compra->id_estado)->esFinal()) {
            return;
        }

        $hayAlza = $this->repo->hayLineasEnAlza($idCompra);
        $this->repo->actualizarRequiereAutorizacion($idCompra, $hayAlza);
        $this->repo->actualizarEstado($idCompra, $hayAlza ? EstadoCompra::REQUIERE_AUTORIZACION : EstadoCompra::APROBADA);
    }

    // =====================================================================
    // MESA DE TRABAJO — precio y paso a "Comprado" (+ auto-envío)
    // =====================================================================

    /** @return array<int>|null Reservado para compatibilidad — ya no auto-envía, siempre null. El envío ahora es una acción explícita (ver enviar()). */
    public function marcarLineaComprada(int $idCompra, int $idLinea, float $precioUnitario, string $idUsuarioSesion): ?array
    {
        if ($precioUnitario <= 0) {
            throw new Exception('El precio unitario debe ser mayor a cero');
        }

        $compra = $this->obtenerCompraOFallar($idCompra);
        $this->exigirEstado($compra, EstadoCompra::APROBADA);

        $linea = $this->obtenerLineaOFallar($idCompra, $idLinea);
        if ((bool) $linea->comprado_con_precio) {
            return null; // ya estaba comprada, no reprocesar (protege el precio histórico)
        }

        $this->repo->marcarLineaComprada($idLinea, $precioUnitario);

        if ($this->repo->contarLineasSinComprar($idCompra) > 0) {
            return null; // aún faltan líneas por fijar precio
        }

        $this->repo->actualizarEstado($idCompra, EstadoCompra::COMPRADA);

        // "Comprado" es un estado real de espera: precio y factura listos,
        // pero todavía no se envía a la agencia. El envío es una acción
        // explícita del Administrador de Bodegas (ver enviar() más abajo) —
        // ya no ocurre solo con fijar el último precio.
        return null;
    }

    // =====================================================================
    // PROCESAMIENTO EN LOTE (ajustes + precios + auto-envío, una sola transacción)
    // =====================================================================

    /**
     * @param array<array{id_linea:int,cantidad_ajustada?:?float,precio_unitario?:?float}> $lineas
     * @return array{lineas_ajustadas:int,lineas_compradas:int,requiere_autorizacion:bool,altas_generadas:bool,ids_altas:array<int>}
     */
    public function procesarLineas(int $idCompra, array $lineas, string $idUsuarioSesion): array
    {
        if (empty($lineas)) {
            throw new Exception('No se recibió ningún cambio para procesar');
        }

        $compra = $this->obtenerCompraOFallar($idCompra);
        $this->exigirEstado($compra, EstadoCompra::APROBADA);

        $ajustadas = 0;
        foreach ($lineas as $i => $linea) {
            $linea   = $this->normalizarLinea($linea);
            $idLinea = (int) ($linea['id_linea'] ?? 0);
            if ($idLinea < 1) {
                throw new Exception('Error de consistencia: la línea #' . ($i + 1) . ' no incluye id_linea');
            }

            if (isset($linea['cantidad_ajustada']) && $linea['cantidad_ajustada'] !== null && $linea['cantidad_ajustada'] !== '') {
                $this->ajustarCantidadLinea($idCompra, $idLinea, (float) $linea['cantidad_ajustada']);
                $ajustadas++;
            }
        }

        // Un alza pudo haber movido la compra a REQUIERE_AUTORIZACION: cortar aquí.
        $compra = $this->obtenerCompraOFallar($idCompra);
        if (EstadoCompra::from((int) $compra->id_estado) === EstadoCompra::REQUIERE_AUTORIZACION) {
            return [
                'lineas_ajustadas' => $ajustadas, 'lineas_compradas' => 0,
                'requiere_autorizacion' => true, 'altas_generadas' => false, 'ids_altas' => [],
            ];
        }

        $compradas = 0;
        $idsAltas  = null; // se mantiene null siempre — el envío ya no ocurre aquí, ver enviar()

        foreach ($lineas as $linea) {
            $linea  = $this->normalizarLinea($linea);
            $precio = isset($linea['precio_unitario']) ? (float) $linea['precio_unitario'] : 0.0;
            if ($precio <= 0) {
                continue;
            }

            $resultadoEnvio = $this->marcarLineaComprada($idCompra, (int) $linea['id_linea'], $precio, $idUsuarioSesion);
            $compradas++;

            if ($resultadoEnvio !== null) {
                $idsAltas = $resultadoEnvio;
            }
        }

        // El envío ya no es automático en ningún camino — marcarLineaComprada()
        // deja la compra en COMPRADA y ahí se queda hasta que se llame a
        // enviar() explícitamente (acción del Administrador de Bodegas).

        return [
            'lineas_ajustadas' => $ajustadas, 'lineas_compradas' => $compradas,
            'requiere_autorizacion' => false, 'altas_generadas' => $idsAltas !== null, 'ids_altas' => $idsAltas ?? [],
        ];
    }

    // =====================================================================
    // ENVÍO — genera las altas físicas
    // =====================================================================

    /** @return array<int> IDs de las altas generadas */
    public function enviar(int $idCompra, string $idUsuarioAdmin): array
    {
        $compra = $this->obtenerCompraOFallar($idCompra);
        $this->exigirEstado($compra, EstadoCompra::COMPRADA);

        $lineas = $this->repo->obtenerLineasParaAlta($idCompra);
        if (empty($lineas)) {
            throw new Exception('No hay líneas pendientes de generar alta en esta compra');
        }

        $idsAltas = [];
        foreach ($lineas as $linea) {
            $idAlta = $this->repo->insertarAlta($idCompra, $linea, $idUsuarioAdmin);
            $this->repo->vincularAlta((int) $linea->id, $idAlta);
            $idsAltas[] = $idAlta;
        }

        $this->repo->actualizarEstado($idCompra, EstadoCompra::ENVIADA);

        return $idsAltas;
    }

    // =====================================================================
    // CIERRE Y CANCELACIÓN
    // =====================================================================

    public function registrar(int $idCompra): void
    {
        $compra = $this->obtenerCompraOFallar($idCompra);
        $this->exigirEstado($compra, EstadoCompra::ENVIADA);
        $this->repo->actualizarEstado($idCompra, EstadoCompra::REGISTRADA);
    }

    public function cancelar(int $idCompra): void
    {
        $compra = $this->obtenerCompraOFallar($idCompra);
        $estado = EstadoCompra::from((int) $compra->id_estado);

        if (!$estado->esCancelable()) {
            throw new Exception('Solo se pueden cancelar compras que aún no tienen ninguna línea comprada');
        }

        $this->repo->actualizarEstado($idCompra, EstadoCompra::CANCELADA);
    }

    // =====================================================================
    // Utilidades internas
    // =====================================================================

    private function obtenerCompraOFallar(int $idCompra): object
    {
        $compra = $this->repo->obtenerCompraConBloqueo($idCompra);
        if (!$compra) {
            throw new Exception('La compra indicada no existe');
        }

        return $compra;
    }

    private function obtenerLineaOFallar(int $idCompra, int $idLinea): object
    {
        $linea = $this->repo->obtenerLineaConBloqueo($idCompra, $idLinea);
        if (!$linea) {
            throw new Exception('La línea indicada no existe en esta compra');
        }

        return $linea;
    }

    private function exigirEstado(object $compra, EstadoCompra $esperado): void
    {
        $actual = EstadoCompra::from((int) $compra->id_estado);
        if ($actual !== $esperado) {
            throw new Exception("Esta operación requiere que la compra esté en estado '{$esperado->nombre()}' (estado actual: '{$actual->nombre()}')");
        }
    }
}