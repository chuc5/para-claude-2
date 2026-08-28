<?php

declare(strict_types=1);

namespace App\inventarioApi\Enums;

enum TipoBodega: int
{
    case AGENCIA = 1;
    case AREA    = 2;

    /** Regla de ajuste en mesa de trabajo: agencia solo puede bajar cantidad, área puede subir o bajar. */
    public function permiteAlza(): bool
    {
        return $this === self::AREA;
    }
}