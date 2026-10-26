<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee la plantilla de nómina (lo que Recursos Humanos acomoda del archivo de Nomipac): un renglón por concepto con
 * Unidad (clave presupuestal de la unidad administrativa), Fuente, Proyecto, Partida, Concepto e Importe. Las
 * columnas se ubican por su encabezado en las primeras filas de la primera hoja.
 */
final class ExcelNomina
{
    public const PLANTILLA = ['Unidad', 'Fuente', 'Proyecto', 'Partida', 'Concepto', 'Importe'];

    private const COLUMNAS = [
        'UNIDAD' => 'unidad', 'UNIDADADMINISTRATIVA' => 'unidad', 'CLAVEUNIDAD' => 'unidad', 'UR' => 'unidad', 'CLAVE' => 'unidad',
        'FUENTE' => 'fuente', 'FUENTEDEFINANCIAMIENTO' => 'fuente', 'FF' => 'fuente',
        'PROYECTO' => 'proyecto', 'CLAVEPROYECTO' => 'proyecto',
        'PARTIDA' => 'partida', 'OBJETODELGASTO' => 'partida', 'COG' => 'partida',
        'CONCEPTO' => 'concepto', 'DESCRIPCION' => 'concepto', 'PERCEPCION' => 'concepto',
        'IMPORTE' => 'importe', 'MONTO' => 'importe', 'TOTAL' => 'importe',
    ];

    /** @return array{renglones: array<int, array{fila: int, unidad: string, fuente: string, proyecto: string, partida: string, concepto: string, importe: float}>, errores: string[]} */
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
            if (count(array_intersect(['unidad', 'fuente', 'proyecto', 'partida', 'importe'], $columnas)) === 5) {
                $encabezado = $n;
                break;
            }
        }
        if ($encabezado === null) {
            return ['renglones' => [], 'errores' => ['No se encontró el encabezado: se necesitan las columnas '.implode(', ', self::PLANTILLA).'.']];
        }

        $renglones = [];
        $errores = [];
        foreach ($filas as $n => $fila) {
            if ($n <= $encabezado) {
                continue;
            }
            $v = [];
            foreach ($columnas as $letra => $campo) {
                $v[$campo] = self::texto($fila[$letra] ?? null);
            }
            if (count(array_filter($v)) === 0) {
                continue;
            }
            $importe = self::numero($fila[array_search('importe', $columnas, true)] ?? null);
            $falta = array_keys(array_filter(['unidad' => $v['unidad'] ?? null, 'fuente' => $v['fuente'] ?? null, 'proyecto' => $v['proyecto'] ?? null, 'partida' => $v['partida'] ?? null], fn ($x) => $x === null));
            if ($falta) {
                $errores[] = "Fila {$n}: falta ".implode(', ', $falta).'.';

                continue;
            }
            if ($importe === null || $importe <= 0) {
                $errores[] = "Fila {$n}: importe no válido (debe ser mayor que cero).";

                continue;
            }
            $renglones[] = [
                'fila' => $n,
                'unidad' => $v['unidad'],
                'fuente' => $v['fuente'],
                'proyecto' => $v['proyecto'],
                'partida' => $v['partida'],
                'concepto' => mb_strtoupper(mb_substr($v['concepto'] ?? '', 0, 300)) ?: 'NÓMINA',
                'importe' => round($importe, 2),
            ];
        }

        return ['renglones' => $renglones, 'errores' => $errores];
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

    private static function texto(mixed $valor): ?string
    {
        // Claves numéricas leídas como número (120.0): sin decimales.
        if (is_float($valor) && floor($valor) === $valor) {
            $valor = (int) $valor;
        }
        $texto = trim(preg_replace('/\s+/', ' ', (string) $valor));

        return $texto === '' ? null : $texto;
    }
}
