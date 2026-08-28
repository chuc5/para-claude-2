<?php

declare(strict_types=1);

namespace App\inventarioApi\Helpers;

/**
 * Validación de rol por puesto — capa adicional al control de ruta/menú.
 * TODO: reemplazar por los IDs reales del catálogo de puestos; hoy ambas
 * constantes apuntan al mismo valor placeholder (56), lo cual es casi
 * seguro un bug si Administrador de Bodegas y Gerencia/Financiero son
 * puestos distintos en producción.
 */
final class RolCompraHelper
{
    public const PUESTO_ADMINISTRADOR_BODEGAS = 56;
    public const PUESTO_GERENCIA_FINANCIERO   = 56; // ajustar: debería ser un ID distinto

    /** @param array<int> $puestosPermitidos */
    public static function usuarioTieneRol(?int $idPuestoSesion, array $puestosPermitidos): bool
    {
        return $idPuestoSesion !== null && in_array($idPuestoSesion, $puestosPermitidos, true);
    }

    public static function esAdministradorBodegas(?int $idPuestoSesion): bool
    {
        return self::usuarioTieneRol($idPuestoSesion, [self::PUESTO_ADMINISTRADOR_BODEGAS]);
    }

    public static function esGerenciaFinanciero(?int $idPuestoSesion): bool
    {
        return self::usuarioTieneRol($idPuestoSesion, [self::PUESTO_GERENCIA_FINANCIERO]);
    }
}