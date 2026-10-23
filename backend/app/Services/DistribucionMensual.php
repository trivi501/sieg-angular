<?php

namespace App\Services;

/**
 * Distribución mensual de una línea del presupuesto: los 12 meses deben sumar exactamente el
 * importe total (ni de más ni de menos). Se compara en centavos enteros para no arrastrar
 * errores de punto flotante.
 */
final class DistribucionMensual
{
    /** Columna de la tabla `presupuesto 2024` => nombre del mes. */
    public const MESES = [
        'ENERO' => 'Enero', 'FEBRERO' => 'Febrero', 'MARZO' => 'Marzo', 'ABRIL' => 'Abril',
        'MAYO' => 'Mayo', 'JUNIO' => 'Junio', 'JULIO' => 'Julio', 'AGOSTO' => 'Agosto',
        'SEP' => 'Septiembre', 'OCTUBRE' => 'Octubre', 'NOV' => 'Noviembre', 'DIC' => 'Diciembre',
    ];

    public static function centavos(float|int|string|null $importe): int
    {
        return (int) round(((float) $importe) * 100);
    }

    /** Suma de los meses en centavos (los meses vacíos cuentan como cero). */
    public static function sumaMeses(array $linea): int
    {
        return array_sum(array_map(fn ($mes) => self::centavos($linea[$mes] ?? 0), array_keys(self::MESES)));
    }

    /** Total menos la suma de los meses, en centavos: > 0 falta distribuir, < 0 sobra. */
    public static function diferencia(float|int|string|null $total, array $linea): int
    {
        return self::centavos($total) - self::sumaMeses($linea);
    }

    public static function mensaje(float|int|string|null $total, array $linea): ?string
    {
        $diferencia = self::diferencia($total, $linea);

        if ($diferencia === 0) {
            return null;
        }

        return sprintf(
            'La distribución mensual suma $%s y debe ser igual al importe total $%s: %s $%s.',
            number_format(self::sumaMeses($linea) / 100, 2),
            number_format(self::centavos($total) / 100, 2),
            $diferencia > 0 ? 'faltan' : 'sobran',
            number_format(abs($diferencia) / 100, 2),
        );
    }
}
