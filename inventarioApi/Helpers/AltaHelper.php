<?php

namespace App\inventarioApi\Helpers;

use App\Core\ApiResponder;
use Exception;
use PDO;

/**
 * AltaHelper
 *
 * Lógica interna del módulo de Altas / Ingresos de Bodega.
 * Centraliza validaciones, ediciones y utilidades específicas
 * de la tabla altas y sus lotes asociados.
 *
 * Todos los métodos que escriben en BD deben ejecutarse dentro de una
 * transacción abierta por la clase principal. Este helper nunca hace
 * commit ni rollback.
 *
 * Dependencias: MovimientoHelper, StockHelper, CierreHelper, ApiResponder.
 */
class AltaHelper
{
    private PDO             $connect;
    private MovimientoHelper $movimientoHelper;
    private StockHelper     $stockHelper;
    private CierreHelper    $cierreHelper;
    private ApiResponder    $res;

    /**
     * @param PDO             $connect
     * @param MovimientoHelper $movimientoHelper
     * @param StockHelper     $stockHelper
     * @param CierreHelper    $cierreHelper
     * @param ApiResponder    $res              Para construir respuestas fail() en ediciones
     */
    public function __construct(
        PDO $connect,
        MovimientoHelper $movimientoHelper,
        StockHelper $stockHelper,
        CierreHelper $cierreHelper,
        ApiResponder $res
    ) {
        $this->connect          = $connect;
        $this->movimientoHelper = $movimientoHelper;
        $this->stockHelper      = $stockHelper;
        $this->cierreHelper     = $cierreHelper;
        $this->res              = $res;
    }

    // =========================================================================
    // VALIDACIÓN DE ALTA EDITABLE
    // =========================================================================

    /**
     * Verifica que el alta sea editable:
     *   - Existe en la BD
     *   - Estado es 1 (Pendiente) o 2 (Parcialmente Recibido)
     *   - El tipo de producto coincide con el esperado
     *
     * @param int $idAlta
     * @param int $tipoEsperado  1=Correlativo | 2=Expiración | 3=Normal
     * @return array|null  Fila del alta con id_tipo, o null si no pasa la validación
     */
    public function verificarAltaEditable(int $idAlta, int $tipoEsperado): ?array
    {
        $stmt = $this->connect->prepare(
            "SELECT a.id, a.id_bodega_destino, a.id_producto, a.id_unidad,
                    a.cantidad_enviada, a.cantidad_ingresada, a.id_estado,
                    a.precio_unitario, p.id_tipo
             FROM   bodega_inventario.altas a
             INNER JOIN bodega_inventario.productos p ON p.id = a.id_producto
             WHERE  a.id = ?
             LIMIT  1"
        );
        $stmt->execute([$idAlta]);
        $alta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alta)                                   return null; // no existe
        if ((int)$alta['id_estado'] === 3)            return null; // ya completado
        if ((int)$alta['id_tipo']   !== $tipoEsperado) return null; // tipo incorrecto

        return $alta;
    }

    // =========================================================================
    // EDICIÓN DE ALTA
    // =========================================================================

    /**
     * Edición completa cuando cantidad_ingresada == 0.
     * Permite cambiar bodega, producto, unidad, cantidad y precio.
     *
     * @param object $datos  Payload del endpoint editarAlta
     * @param array  $alta   Fila actual del alta
     * @return array  Respuesta ok() | fail()
     */
    public function editarAltaCompleta(object $datos, array $alta): array
    {
        $idBodega = isset($datos->id_bodega_destino)
            ? (int)$datos->id_bodega_destino
            : (int)$alta['id_bodega_destino'];

        $idProd = isset($datos->id_producto)
            ? (int)$datos->id_producto
            : (int)$alta['id_producto'];

        $idUnidad = isset($datos->id_unidad)
            ? (int)$datos->id_unidad
            : (int)$alta['id_unidad'];

        $cantidad = isset($datos->cantidad_enviada)
            ? (float)$datos->cantidad_enviada
            : (float)$alta['cantidad_enviada'];

        // precio_unitario: null = limpiar; ausente = conservar
        $precio = array_key_exists('precio_unitario', (array)$datos)
            ? (isset($datos->precio_unitario) && $datos->precio_unitario !== ''
                ? (float)$datos->precio_unitario : null)
            : $alta['precio_unitario'];

        if ($cantidad <= 0) {
            return $this->res->fail('La cantidad enviada debe ser mayor a 0');
        }

        // Validar bodega activa
        $stmtB = $this->connect->prepare(
            "SELECT id FROM bodega_inventario.bodegas WHERE id = ? AND activo = 1 LIMIT 1"
        );
        $stmtB->execute([$idBodega]);
        if (!$stmtB->fetch()) {
            return $this->res->fail('La bodega no existe o está inactiva');
        }

        // Validar que la unidad pertenece al producto y ambos activos
        $stmtPU = $this->connect->prepare(
            "SELECT pu.id
             FROM   bodega_inventario.productos_unidades pu
             INNER JOIN bodega_inventario.productos p ON p.id = pu.id_producto
             WHERE  pu.id_producto = ? AND pu.id_unidad = ?
               AND  pu.activo = 1 AND p.activo = 1
             LIMIT 1"
        );
        $stmtPU->execute([$idProd, $idUnidad]);
        if (!$stmtPU->fetch()) {
            return $this->res->fail('El producto o la unidad de medida no son válidos');
        }

        $this->connect->prepare(
            "UPDATE bodega_inventario.altas
             SET id_bodega_destino = ?,
                 id_producto       = ?,
                 id_unidad         = ?,
                 cantidad_enviada  = ?,
                 precio_unitario   = ?,
                 updated_at        = NOW()
             WHERE id = ?"
        )->execute([$idBodega, $idProd, $idUnidad, $cantidad, $precio, (int)$alta['id']]);

        return $this->res->ok('Alta actualizada correctamente');
    }

    /**
     * Ajuste parcial cuando ya hay ingresos pero el alta no está completada.
     * Solo permite reducir cantidad_enviada (con motivo obligatorio)
     * y editar precio si no hay lotes con precio ya asignado.
     *
     * @param object $datos  Payload del endpoint editarAlta
     * @param array  $alta   Fila actual del alta
     * @return array  Respuesta ok() | info() | fail()
     */
    public function ajustarAltaParcial(object $datos, array $alta): array
    {
        $motivo  = trim($datos->motivo ?? '');
        $idAlta  = (int)$alta['id'];
        $ingresada = (float)$alta['cantidad_ingresada'];
        $cambios = [];

        // ── Ajuste de cantidad ──────────────────────────────────────────────
        if (isset($datos->cantidad_enviada)) {
            $nuevaCantidad = (float)$datos->cantidad_enviada;

            if (!$motivo) {
                return $this->res->fail(
                    'El motivo es obligatorio al ajustar la cantidad enviada'
                );
            }
            if ($nuevaCantidad <= 0) {
                return $this->res->fail('La cantidad enviada debe ser mayor a 0');
            }
            if ($nuevaCantidad < $ingresada) {
                return $this->res->fail(
                    "La nueva cantidad ({$nuevaCantidad}) no puede ser menor a lo ya ingresado ({$ingresada})"
                );
            }

            $cambios['cantidad_enviada'] = $nuevaCantidad;
            $cambios['id_estado']        = $nuevaCantidad <= $ingresada
                ? 3 : (int)$alta['id_estado'];
        }

        // ── Ajuste de precio ────────────────────────────────────────────────
        if (array_key_exists('precio_unitario', (array)$datos)) {
            if ($this->tieneLotesConPrecio($idAlta)) {
                return $this->res->fail(
                    'No se puede cambiar el precio: ya existen lotes ingresados con precio asignado'
                );
            }
            $cambios['precio_unitario'] = isset($datos->precio_unitario)
            && $datos->precio_unitario !== ''
                ? (float)$datos->precio_unitario : null;
        }

        if (empty($cambios)) {
            return $this->res->info('No hay cambios que aplicar');
        }

        // Construir SET dinámico
        $sets   = [];
        $params = [];
        foreach ($cambios as $campo => $valor) {
            $sets[]   = "{$campo} = ?";
            $params[] = $valor;
        }
        $sets[]   = "updated_at = NOW()";
        $params[] = $idAlta;

        $this->connect->prepare(
            "UPDATE bodega_inventario.altas SET " . implode(', ', $sets) . " WHERE id = ?"
        )->execute($params);

        return $this->res->ok('Alta ajustada correctamente');
    }

    // =========================================================================
    // UTILIDADES DE LOTES
    // =========================================================================

    /**
     * Devuelve el nombre de la tabla de lotes según el tipo textual.
     *
     * @throws \InvalidArgumentException si el tipo no es válido
     */
    public function tablaPorTipo(string $tipo): string
    {
        return match ($tipo) {
            'correlativo' => 'lotes_correlativo',
            'expiracion'  => 'lotes_expiracion',
            'normal'      => 'lotes_normal',
            default       => throw new \InvalidArgumentException(
                "Tipo de lote inválido: {$tipo}"
            ),
        };
    }

    /**
     * Calcula la cantidad original de un lote antes de cualquier consumo.
     *
     * Para correlativos: correlativo_final - correlativo_inicial + 1.
     * Para expiración y normal: cantidad_disponible (la validación previa
     * ya garantiza que son iguales si no hay consumos).
     */
    public function cantidadOriginalLote(string $tipo, array $lote): float
    {
        if ($tipo === 'correlativo') {
            return (float)(
                (int)$lote['correlativo_final'] - (int)$lote['correlativo_inicial'] + 1
            );
        }

        return (float)$lote['cantidad_disponible'];
    }

    /**
     * Verifica si ya existen lotes con precio_unitario asignado para un alta.
     * Bloquea el cambio de precio cuando los lotes ya están valorizados.
     */
    public function tieneLotesConPrecio(int $idAlta): bool
    {
        $tablas = ['lotes_correlativo', 'lotes_expiracion', 'lotes_normal'];

        foreach ($tablas as $tabla) {
            $stmt = $this->connect->prepare(
                "SELECT 1 FROM bodega_inventario.{$tabla}
                 WHERE id_alta = ? AND precio_unitario IS NOT NULL
                 LIMIT 1"
            );
            $stmt->execute([$idAlta]);
            if ($stmt->fetch()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Devuelve el campo de fecha de creación según el tipo de lote.
     * Los lotes normales usan fecha_ingreso; los demás usan created_at.
     */
    public function campoFechaCreacion(string $tipo): string
    {
        return $tipo === 'normal' ? 'fecha_ingreso' : 'created_at';
    }

    /**
     * Actualiza cantidad_ingresada y estado del alta tras un ingreso de lotes.
     *
     * @param int   $idAlta
     * @param float $cantidadIngresadaActual  Valor actual antes del ingreso
     * @param float $cantidadEnviada          Total enviado (del alta)
     * @param float $cantidadNueva            Cantidad del lote recién ingresado
     * @return int  Nuevo estado (2 = Parcialmente Recibido, 3 = Completado)
     */
    public function actualizarTotalesAlta(
        int $idAlta,
        float $cantidadIngresadaActual,
        float $cantidadEnviada,
        float $cantidadNueva
    ): int {
        $nuevaIngresada = $cantidadIngresadaActual + $cantidadNueva;
        $nuevoEstado    = $nuevaIngresada >= $cantidadEnviada ? 3 : 2;

        $this->connect->prepare(
            "UPDATE bodega_inventario.altas
             SET cantidad_ingresada = ?, id_estado = ?
             WHERE id = ?"
        )->execute([$nuevaIngresada, $nuevoEstado, $idAlta]);

        return $nuevoEstado;
    }
}