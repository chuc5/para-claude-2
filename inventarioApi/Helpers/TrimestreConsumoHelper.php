<?php

declare(strict_types=1);

namespace App\inventarioApi\Helpers;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * TrimestreConsumoHelper
 *
 * Un solo lugar para definir "qué trimestre de consumo le toca revisar al
 * pedido trimestral hoy" — si el negocio decide cambiar en qué mes arranca
 * el ciclo de trimestres, se toca ÚNICAMENTE la constante
 * `MES_INICIO_PRIMER_TRIMESTRE` de más abajo, nada más.
 *
 * ── Cómo configurar el desfase ──────────────────────────────────────────
 * `MES_INICIO_PRIMER_TRIMESTRE` es el mes (1-12) en el que arranca el
 * primer trimestre del ciclo; los otros 3 se calculan solos, cada uno 3
 * meses después, dando la vuelta al año si hace falta. Ejemplos:
 *
 *   MES_INICIO_PRIMER_TRIMESTRE = 1  (enero, valor por defecto)
 *     → Ene-Mar · Abr-Jun · Jul-Sep · Oct-Dic
 *
 *   MES_INICIO_PRIMER_TRIMESTRE = 12  (diciembre)
 *     → Dic-Feb · Mar-May · Jun-Ago · Sep-Nov
 *
 *   MES_INICIO_PRIMER_TRIMESTRE = 11  (noviembre)
 *     → Nov-Ene · Feb-Abr · May-Jul · Ago-Oct
 *
 * En los tres casos, la regla de "cuál es el objetivo" no cambia: siempre
 * es el ÚLTIMO trimestre ya cerrado respecto a la fecha de hoy.
 */
final class TrimestreConsumoHelper
{
    /** Mes (1-12) en que arranca el primer trimestre del ciclo. Único valor a tocar para reconfigurar. */
    private const MES_INICIO_PRIMER_TRIMESTRE = 12;

    private const MESES_ABREV = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /**
     * @return array{anio:int, trimestre:int, inicio:string, fin:string, etiqueta:string}
     *         inicio/fin en formato 'Y-m-d H:i:s', inicio inclusive y fin exclusivo.
     *         `anio` es solo la etiqueta interna del ciclo (para identificarlo en
     *         `trimestres_generados`) — no necesariamente el año calendario del
     *         mes en que arranca, cuando el ciclo cruza fin de año (ej. Dic-Feb).
     */
    public function obtenerTrimestreObjetivo(?DateTimeImmutable $fecha = null): array
    {
        $fecha = $fecha ?? new DateTimeImmutable('now');

        [$anioActual, $trimestreActual] = $this->trimestreDeFecha($fecha);

        $trimestreObjetivo = $trimestreActual - 1;
        $anioObjetivo       = $anioActual;

        if ($trimestreObjetivo < 1) {
            $trimestreObjetivo = 4;
            $anioObjetivo--;
        }

        [$inicio, $fin] = $this->rangoDelTrimestre($anioObjetivo, $trimestreObjetivo);

        return [
            'anio'      => $anioObjetivo,
            'trimestre' => $trimestreObjetivo,
            'inicio'    => $inicio,
            'fin'       => $fin,
            'etiqueta'  => $this->etiquetaTrimestre($anioObjetivo, $trimestreObjetivo),
        ];
    }

    /**
     * A qué trimestre del ciclo (año interno, número 1-4) pertenece una fecha,
     * según el mes de arranque configurado en MES_INICIO_PRIMER_TRIMESTRE.
     *
     * @return array{0:int,1:int} [anio, trimestre]
     */
    private function trimestreDeFecha(DateTimeImmutable $fecha): array
    {
        $anio = (int) $fecha->format('Y');
        $mes  = (int) $fecha->format('n');

        $mesesDesdeAncla = $mes - self::MES_INICIO_PRIMER_TRIMESTRE;
        if ($mesesDesdeAncla < 0) {
            $mesesDesdeAncla += 12;
            $anio--; // la fecha cae dentro del ciclo que arrancó el año calendario anterior
        }

        $trimestre = intdiv($mesesDesdeAncla, 3) + 1; // 1..4

        return [$anio, $trimestre];
    }

    /** @return array{0:string,1:string} [inicio inclusive, fin exclusivo) en formato 'Y-m-d H:i:s' */
    public function rangoDelTrimestre(int $anio, int $trimestre): array
    {
        if ($trimestre < 1 || $trimestre > 4) {
            throw new InvalidArgumentException("Trimestre inválido: {$trimestre} (debe ser 1 a 4)");
        }

        $mesInicioAbsoluto = self::MES_INICIO_PRIMER_TRIMESTRE + ($trimestre - 1) * 3;
        $anioInicio        = $anio + intdiv($mesInicioAbsoluto - 1, 12);
        $mesInicio         = (($mesInicioAbsoluto - 1) % 12) + 1;

        $inicio = sprintf('%04d-%02d-01 00:00:00', $anioInicio, $mesInicio);
        $fin    = (new DateTimeImmutable($inicio))->modify('+3 months')->format('Y-m-d H:i:s');

        return [$inicio, $fin];
    }

    /** Etiqueta legible, resuelta dinámicamente contra el rango real (soporta ciclos que cruzan de año, ej. Dic-Feb). */
    public function etiquetaTrimestre(int $anio, int $trimestre): string
    {
        [$inicio, $fin] = $this->rangoDelTrimestre($anio, $trimestre);

        $fechaInicio = new DateTimeImmutable($inicio);
        // En lugar de modificar días sobre la fecha fin:
        $fechaFin = (new DateTimeImmutable($fin))->modify('-1 second'); // último día real del trimestre

        $mesInicio  = self::MESES_ABREV[(int) $fechaInicio->format('n')];
        $anioInicio = (int) $fechaInicio->format('Y');
        $mesFin     = self::MESES_ABREV[(int) $fechaFin->format('n')];
        $anioFin    = (int) $fechaFin->format('Y');

        if ($anioInicio === $anioFin) {
            return "{$mesInicio}-{$mesFin} {$anioInicio}";
        }

        return "{$mesInicio} {$anioInicio} - {$mesFin} {$anioFin}";
    }
}