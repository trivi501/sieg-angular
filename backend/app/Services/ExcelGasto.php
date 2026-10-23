<?php

namespace App\Services;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Excel de "Ejercicio y control del gasto": una fila por línea de presupuesto con sus momentos y
 * una fila de totales con fórmulas. Los importes van como números con formato #,##0.00
 * (miles con coma, centavos con punto en Excel con configuración de México).
 */
final class ExcelGasto
{
    public const IMPORTES = [
        'asignado' => 'Aprobado',
        'modificado' => 'Modificado',
        'vigente' => 'Vigente',
        'comprometido' => 'Comprometido',
        'devengado' => 'Devengado',
        'pagado' => 'Pagado',
        'disponible' => 'Disponible',
        'saldo_corrido' => 'Saldo corrido',
        'disponible_mes' => 'Disponible del mes',
    ];

    private const FORMATO = '#,##0.00;[Red]-#,##0.00';

    public function libro(Collection $filas, array $filtros): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Ejercicio del gasto');

        $encabezados = [
            'Clave unidad', 'Unidad', 'Fuente', 'Nombre de la fuente', 'Proyecto', 'Nombre del proyecto',
            'Partida', 'Nombre de la partida', ...array_values(self::IMPORTES),
        ];
        $ultima = Coordinate::stringFromColumnIndex(count($encabezados));
        $primerImporte = 9;

        $hoja->setCellValue('A1', 'Ejercicio y control del gasto');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $hoja->setCellValue('A2', implode(' · ', $filtros).' · Generado el '.now('America/Mexico_City')->format('d/m/Y H:i'));
        $hoja->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        $hoja->fromArray($encabezados, null, 'A4');
        $hoja->getStyle("A4:{$ultima}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '7A1F3D']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $renglon = 5;
        foreach ($filas as $l) {
            // Comparación estricta: sin ella fromArray() toma el 0 como vacío y deja la celda en blanco.
            // Los importes sin movimiento se escriben como 0.
            $hoja->fromArray([
                $l['unidad_clave'], $l['unidad'], $l['fuente'], $l['nombre_fuente'],
                $l['proyecto'], $l['nombre_proyecto'], $l['partida'], $l['nombre_partida'],
                ...array_map(fn ($c) => (float) ($l[$c] ?? 0), array_keys(self::IMPORTES)),
            ], null, "A{$renglon}", true);

            // Las claves como texto para que Excel no les quite ceros ni las convierta en número.
            foreach (['A', 'C', 'E', 'G'] as $columna) {
                $hoja->setCellValueExplicit("{$columna}{$renglon}", (string) $hoja->getCell("{$columna}{$renglon}")->getValue(), DataType::TYPE_STRING);
            }

            $renglon++;
        }

        $ultimoDato = $renglon - 1;
        $hoja->setCellValue("A{$renglon}", 'Total');

        for ($i = 0; $i < count(self::IMPORTES); $i++) {
            $col = Coordinate::stringFromColumnIndex($primerImporte + $i);
            $hoja->setCellValue("{$col}{$renglon}", $ultimoDato >= 5 ? "=SUM({$col}5:{$col}{$ultimoDato})" : 0);
        }

        $hoja->getStyle("A{$renglon}:{$ultima}{$renglon}")->getFont()->setBold(true);
        $hoja->getStyle("A{$renglon}:{$ultima}{$renglon}")->getBorders()->getTop()->setBorderStyle('thin');

        $primera = Coordinate::stringFromColumnIndex($primerImporte);
        $hoja->getStyle("{$primera}5:{$ultima}{$renglon}")->getNumberFormat()->setFormatCode(self::FORMATO);

        foreach ([12, 30, 8, 28, 10, 32, 9, 36] as $i => $ancho) {
            $hoja->getColumnDimensionByColumn($i + 1)->setWidth($ancho);
        }
        for ($i = 0; $i < count(self::IMPORTES); $i++) {
            $hoja->getColumnDimensionByColumn($primerImporte + $i)->setWidth(16);
        }

        $hoja->freezePane('A5');
        $hoja->setAutoFilter("A4:{$ultima}{$ultimoDato}");

        return $libro;
    }
}
