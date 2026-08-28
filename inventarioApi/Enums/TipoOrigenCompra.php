<?php

declare(strict_types=1);

namespace App\inventarioApi\Enums;

/**
 * De dónde nace la compra. Reemplaza tipos_orden, separando "Solicitud
 * encargado" en Agencia/Área para que el origen sea auto-explicativo sin
 * tener que mirar el tipo de bodega destino.
 */
enum TipoOrigenCompra: int
{
    case TRIMESTRAL      = 1;
    case EXTRAORDINARIA  = 2;
    case SOLICITUD_AGENCIA = 3;
    case SOLICITUD_AREA    = 4;
    case CARGA_MASIVA      = 5;

    public function nombre(): string
    {
        return match ($this) {
            self::TRIMESTRAL => 'Trimestral',
            self::EXTRAORDINARIA => 'Extraordinaria',
            self::SOLICITUD_AGENCIA => 'Solicitud de Agencia',
            self::SOLICITUD_AREA => 'Solicitud de Área',
            self::CARGA_MASIVA => 'Carga Masiva',
        };
    }

    /** true = nace con solicitante/gestor (pasa por SOLICITADA); false = la genera directo el sistema/admin. */
    public function requiereFlujoSolicitud(): bool
    {
        return in_array($this, [self::SOLICITUD_AGENCIA, self::SOLICITUD_AREA], true);
    }
}