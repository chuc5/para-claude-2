<?php

declare(strict_types=1);

namespace App\inventarioApi\Enums;

/**
 * Estados del ciclo de vida único de una compra (tabla `compras`).
 * Reemplaza estados_solicitud_compra + estados_compra: antes existían dos
 * catálogos porque solicitud y orden eran filas distintas; ahora son el
 * mismo registro avanzando de estado.
 */
enum EstadoCompra: int
{
    case SOLICITADA             = 1; // esperando decisión del gestor (flujos Agencia/Área)
    case APROBADA                = 2; // lista para mesa de trabajo (ajustar cantidad / fijar precio)
    case RECHAZADA               = 3; // cierre negativo, no continúa
    case REQUIERE_AUTORIZACION   = 4; // esperando a Gerencia/Financiero (alza o extraordinaria)
    case COMPRADA                = 5; // todas las líneas tienen precio fijado
    case ENVIADA                 = 6; // altas generadas, encargado puede recibir físicamente
    case REGISTRADA              = 7; // ciclo cerrado administrativamente
    case CANCELADA               = 8;

    public function nombre(): string
    {
        return match ($this) {
            self::SOLICITADA => 'Solicitada',
            self::APROBADA => 'Aprobada',
            self::RECHAZADA => 'Rechazada',
            self::REQUIERE_AUTORIZACION => 'Requiere Autorización',
            self::COMPRADA => 'Comprado',
            self::ENVIADA => 'Enviado',
            self::REGISTRADA => 'Registrado',
            self::CANCELADA => 'Cancelada',
        };
    }

    /** Estados desde los que ya no se puede cancelar ni recalcular autorización. */
    public function esFinal(): bool
    {
        return in_array($this, [self::RECHAZADA, self::ENVIADA, self::REGISTRADA, self::CANCELADA], true);
    }

    /** Estados desde los que aún se permite cancelar la compra completa. */
    public function esCancelable(): bool
    {
        return in_array($this, [self::SOLICITADA, self::APROBADA, self::REQUIERE_AUTORIZACION], true);
    }
}