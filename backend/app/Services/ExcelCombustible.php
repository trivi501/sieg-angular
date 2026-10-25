<?php

namespace App\Services;

use App\Models\CombustibleCarga;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Lee el reporte del proveedor de combustible ("REPORTE MENSUAL"): una hoja por mes, el encabezado en las primeras
 * filas y una fila por ticket. Las columnas se ubican por su encabezado, no por su letra. Las filas sin litros o
 * sin monto numéricos (títulos, subtotales de quincena) se ignoran.
 */
final class ExcelCombustible
{
    /** Encabezado normalizado (sin acentos, signos ni espacios) → campo. */
    private const COLUMNAS = [
        'CLAVE' => 'clave',
        'FECHADELTICKET' => 'fecha_ticket',
        'FECHADELVALE' => 'fecha_vale',
        'NOTIKET' => 'ticket',
        'NOTICKET' => 'ticket',
        'FOLIOVALE' => 'vale',
        'NOECO' => 'numero_economico',
        'NOECONOMICO' => 'numero_economico',
        'SECRETARIA' => 'secretaria',
        'DEPARTAMENTO' => 'departamento',
        'NOMBRE' => 'conductor',
        'GASOLINADIESEL' => 'producto',
        'LITROS' => 'litros',
        'MONTO' => 'importe',
        'PRECIOPORLITRO' => 'precio',
        'FECHADEFACTURA' => 'fecha_factura',
        'FOLIO' => 'factura_folio',
        'PROVEEDOR' => 'proveedor',
    ];

    private const REQUERIDAS = ['clave' => 'CLAVE', 'litros' => 'LITROS', 'importe' => 'MONTO', 'factura_folio' => 'FOLIO (de la factura)'];

    /** Sin número económico: vehículos particulares. */
    private const PARTICULARES = ['PART', 'PARTICULAR', 'PARTICULARES'];

    /** @return string[] Nombres de las hojas del libro. */
    public static function hojas(string $ruta): array
    {
        return IOFactory::createReaderForFile($ruta)->listWorksheetNames($ruta);
    }

    /**
     * Tickets de una hoja (por omisión, la primera).
     *
     * @return array{hoja: string, tickets: array<int, array<string, mixed>>, errores: string[], avisos: string[]}
     */
    public static function leer(string $ruta, ?string $hoja = null): array
    {
        $lector = IOFactory::createReaderForFile($ruta);
        $lector->setReadDataOnly(true);
        if ($hoja !== null) {
            $lector->setLoadSheetsOnly([$hoja]);
        }
        $libro = $lector->load($ruta);
        $hojaExcel = $hoja !== null ? $libro->getSheetByName($hoja) : $libro->getSheet(0);
        if (! $hojaExcel) {
            return ['hoja' => (string) $hoja, 'tickets' => [], 'errores' => ["El archivo no tiene la hoja {$hoja}."], 'avisos' => []];
        }

        [$filaEncabezado, $columnas] = self::encabezado($hojaExcel);
        $faltan = array_diff_key(self::REQUERIDAS, array_flip($columnas));
        if ($filaEncabezado === null || $faltan) {
            return ['hoja' => $hojaExcel->getTitle(), 'tickets' => [], 'errores' => [
                'No se encontró el encabezado del reporte del proveedor'.($faltan ? ' (faltan las columnas '.implode(', ', $faltan).')' : '').'.',
            ], 'avisos' => []];
        }

        $tickets = [];
        $errores = [];
        $avisos = [];
        $ultima = $hojaExcel->getHighestDataRow();
        for ($fila = $filaEncabezado + 1; $fila <= $ultima; $fila++) {
            $v = [];
            foreach ($columnas as $letra => $campo) {
                $v[$campo] = $hojaExcel->getCell($letra.$fila)->getCalculatedValue();
            }
            $litros = self::numero($v['litros'] ?? null);
            $importe = self::numero($v['importe'] ?? null);
            if ($litros === null || $importe === null) {
                continue; // título, subtotal o fila vacía
            }
            $folio = CombustibleCarga::normalizarFolio((string) ($v['factura_folio'] ?? ''));
            $clave = mb_strtoupper(trim((string) ($v['clave'] ?? '')));
            if ($folio === '' || $clave === '') {
                $errores[] = "Fila {$fila}: falta ".($clave === '' ? 'la clave de la secretaría' : 'el folio de la factura').'.';

                continue;
            }
            $economico = mb_strtoupper(trim((string) ($v['numero_economico'] ?? '')));
            $fechas = [];
            foreach (['fecha_ticket' => 'del ticket', 'fecha_vale' => 'del vale', 'fecha_factura' => 'de la factura'] as $campo => $texto) {
                $fechas[$campo] = self::fecha($v[$campo] ?? null);
                if ($fechas[$campo] === null && ! self::vacio($v[$campo] ?? null)) {
                    $avisos[] = "Fila {$fila}: la fecha {$texto} no es válida (".(is_numeric($v[$campo]) ? 'número '.$v[$campo] : $v[$campo]).'); se deja vacía.';
                }
            }

            $tickets[] = [
                'fila' => $fila,
                'clave' => $clave,
                'secretaria' => self::texto($v['secretaria'] ?? null),
                'fecha_ticket' => $fechas['fecha_ticket'],
                'fecha_vale' => $fechas['fecha_vale'],
                'ticket' => self::texto($v['ticket'] ?? null, 40),
                'vale' => self::texto($v['vale'] ?? null, 40),
                'numero_economico' => in_array($economico, self::PARTICULARES, true) ? 'PARTICULAR' : (mb_substr($economico, 0, 30) ?: null),
                'departamento' => self::texto($v['departamento'] ?? null, 200),
                'conductor' => self::texto($v['conductor'] ?? null, 200),
                'producto' => self::texto($v['producto'] ?? null, 40),
                'litros' => round($litros, 3),
                'precio' => ($p = self::numero($v['precio'] ?? null)) !== null ? round($p, 4) : ($litros > 0 ? round($importe / $litros, 4) : null),
                'importe' => round($importe, 2),
                'factura_folio' => mb_substr($folio, 0, 40),
                'fecha_factura' => $fechas['fecha_factura'],
                'proveedor' => self::texto($v['proveedor'] ?? null, 300),
            ];
        }

        $titulo = $hojaExcel->getTitle();
        // Libera el libro (un mes de tickets ocupa decenas de MB) antes de seguir con lo leído.
        $libro->disconnectWorksheets();
        unset($libro, $hojaExcel);

        return ['hoja' => $titulo, 'tickets' => $tickets, 'errores' => $errores, 'avisos' => $avisos];
    }

    /** @return array{0: int|null, 1: array<string, string>} Fila del encabezado y letra → campo. */
    private static function encabezado(Worksheet $hoja): array
    {
        $ultimaColumna = $hoja->getHighestDataColumn();
        for ($fila = 1; $fila <= min(15, $hoja->getHighestDataRow()); $fila++) {
            $columnas = [];
            foreach ($hoja->getRowIterator($fila, $fila)->current()->getCellIterator('A', $ultimaColumna) as $celda) {
                $campo = self::COLUMNAS[self::normalizar((string) $celda->getValue())] ?? null;
                // Si un encabezado se repite, se queda la primera columna.
                if ($campo && ! in_array($campo, $columnas, true)) {
                    $columnas[$celda->getColumn()] = $campo;
                }
            }
            if (in_array('litros', $columnas, true) && in_array('clave', $columnas, true)) {
                return [$fila, $columnas];
            }
        }

        return [null, []];
    }

    private static function normalizar(string $texto): string
    {
        $sinAcentos = strtr(mb_strtoupper(trim($texto)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return preg_replace('/[^A-Z0-9]/', '', $sinAcentos);
    }

    private static function numero(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        $texto = str_replace([',', '$', ' '], '', trim((string) $valor));

        return is_numeric($texto) ? (float) $texto : null;
    }

    private static function texto(mixed $valor, int $max = 300): ?string
    {
        $texto = trim(preg_replace('/\s+/', ' ', (string) $valor));

        return $texto === '' ? null : mb_substr(mb_strtoupper($texto), 0, $max);
    }

    /** Fecha de Excel (número de serie) o texto dd/mm/aaaa; null si no es una fecha razonable (p. ej. año 20226). */
    private static function fecha(mixed $valor): ?string
    {
        $fecha = null;
        try {
            if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
                $fecha = ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d');
            } elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', trim((string) $valor), $m)) {
                $fecha = CarbonImmutable::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            }
        } catch (Throwable) {
        }
        $año = $fecha ? (int) strtok($fecha, '-') : 0;

        return $año >= 2000 && $año <= 2100 ? $fecha : null;
    }

    private static function vacio(mixed $valor): bool
    {
        return $valor === null || trim((string) $valor) === '';
    }
}
