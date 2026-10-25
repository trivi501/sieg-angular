<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Lee movimientos bancarios de la plantilla de Tesorería (Fecha, Tipo, Importe, Referencia, Clave de rastreo,
 * Concepto) o de un estado de cuenta con columnas separadas de cargo y abono (Retiro / Depósito). Las columnas se
 * ubican por su encabezado en las primeras filas de la primera hoja.
 */
final class ExcelMovimientos
{
    public const PLANTILLA = ['Fecha', 'Tipo', 'Importe', 'Referencia', 'Clave de rastreo', 'Concepto'];

    private const COLUMNAS = [
        'FECHA' => 'fecha', 'FECHADEOPERACION' => 'fecha', 'FECHAOPERACION' => 'fecha',
        'TIPO' => 'tipo', 'IMPORTE' => 'importe', 'MONTO' => 'importe',
        'CARGO' => 'cargo', 'CARGOS' => 'cargo', 'RETIRO' => 'cargo', 'RETIROS' => 'cargo',
        'ABONO' => 'abono', 'ABONOS' => 'abono', 'DEPOSITO' => 'abono', 'DEPOSITOS' => 'abono',
        'REFERENCIA' => 'referencia', 'CLAVEDERASTREO' => 'clave_rastreo', 'RASTREO' => 'clave_rastreo',
        'CONCEPTO' => 'concepto', 'DESCRIPCION' => 'concepto',
    ];

    /** @return array{movimientos: array<int, array{fila: int, fecha: string, tipo: string, importe: float, referencia: ?string, clave_rastreo: ?string, concepto: ?string}>, errores: string[]} */
    public static function leer(string $ruta): array
    {
        $lector = IOFactory::createReaderForFile($ruta);
        $lector->setReadDataOnly(true);
        $filas = $lector->load($ruta)->getSheet(0)->toArray(null, true, false, true);

        [$encabezado, $columnas] = [null, []];
        foreach (array_slice($filas, 0, 15, true) as $n => $fila) {
            $columnas = [];
            foreach ($fila as $letra => $valor) {
                $campo = self::COLUMNAS[self::normalizar((string) $valor)] ?? null;
                if ($campo && ! in_array($campo, $columnas, true)) {
                    $columnas[$letra] = $campo;
                }
            }
            if (in_array('fecha', $columnas, true) && (in_array('importe', $columnas, true) || in_array('cargo', $columnas, true) || in_array('abono', $columnas, true))) {
                $encabezado = $n;
                break;
            }
        }
        if ($encabezado === null) {
            return ['movimientos' => [], 'errores' => ['No se encontró el encabezado: se necesitan las columnas Fecha e Importe (con Tipo), o Cargo y Abono.']];
        }

        $movimientos = [];
        $errores = [];
        foreach ($filas as $n => $fila) {
            if ($n <= $encabezado) {
                continue;
            }
            $v = [];
            foreach ($columnas as $letra => $campo) {
                $v[$campo] = $fila[$letra] ?? null;
            }
            if (count(array_filter($v, fn ($x) => $x !== null && trim((string) $x) !== '')) === 0) {
                continue;
            }

            [$tipo, $importe] = self::tipoEImporte($v);
            $fecha = self::fecha($v['fecha'] ?? null);
            if (! $fecha || ! $tipo || $importe === null || $importe <= 0) {
                $errores[] = "Fila {$n}: ".(! $fecha ? 'fecha no válida' : (! $tipo ? 'falta el tipo (CARGO o ABONO)' : 'importe no válido')).'.';

                continue;
            }
            $movimientos[] = [
                'fila' => $n,
                'fecha' => $fecha,
                'tipo' => $tipo,
                'importe' => round($importe, 2),
                'referencia' => self::texto($v['referencia'] ?? null, 100),
                'clave_rastreo' => self::texto($v['clave_rastreo'] ?? null, 60),
                'concepto' => self::texto($v['concepto'] ?? null, 500),
            ];
        }

        return ['movimientos' => $movimientos, 'errores' => $errores];
    }

    /** @return array{0: ?string, 1: ?float} */
    private static function tipoEImporte(array $v): array
    {
        $cargo = self::numero($v['cargo'] ?? null);
        $abono = self::numero($v['abono'] ?? null);
        if ($cargo || $abono) {
            return $cargo ? ['cargo', abs($cargo)] : ['abono', abs($abono)];
        }
        $importe = self::numero($v['importe'] ?? null);
        $tipo = match (true) {
            str_starts_with(self::normalizar((string) ($v['tipo'] ?? '')), 'CARGO'), str_starts_with(self::normalizar((string) ($v['tipo'] ?? '')), 'RETIRO') => 'cargo',
            str_starts_with(self::normalizar((string) ($v['tipo'] ?? '')), 'ABONO'), str_starts_with(self::normalizar((string) ($v['tipo'] ?? '')), 'DEPOSITO') => 'abono',
            // Sin tipo: el signo dice (negativo = cargo).
            blank($v['tipo'] ?? null) && $importe !== null => $importe < 0 ? 'cargo' : 'abono',
            default => null,
        };

        return [$tipo, $importe === null ? null : abs($importe)];
    }

    private static function normalizar(string $texto): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtr(mb_strtoupper(trim($texto)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']));
    }

    private static function numero(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        $texto = str_replace([',', '$', ' '], '', trim((string) $valor));

        return is_numeric($texto) ? (float) $texto : null;
    }

    private static function texto(mixed $valor, int $max): ?string
    {
        $texto = trim(preg_replace('/\s+/', ' ', (string) $valor));

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }

    private static function fecha(mixed $valor): ?string
    {
        try {
            $fecha = match (true) {
                is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor)) => ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d'),
                (bool) preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', trim((string) $valor), $m) => CarbonImmutable::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString(),
                (bool) preg_match('#^\d{4}-\d{2}-\d{2}$#', trim((string) $valor)) => trim((string) $valor),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
        $año = $fecha ? (int) strtok($fecha, '-') : 0;

        return $año >= 2000 && $año <= 2100 ? $fecha : null;
    }
}
