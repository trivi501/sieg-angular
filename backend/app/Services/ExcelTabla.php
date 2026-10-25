<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Excel de un listado (bitácora de combustible, reportes de consumo y de Tesorería): título, subtítulo con la fecha
 * de generación, encabezado guinda, filas y totales con fórmulas. Lo que no es importe va como texto.
 */
final class ExcelTabla
{
    /**
     * Libro de una tabla: título, subtítulo, encabezado guinda, filas y renglón de
     * totales con fórmulas en las columnas indicadas.
     *
     * @param  array<int, string>  $encabezados
     * @param  iterable<array<int, mixed>>  $filas
     * @param  array<int, string>  $formatos  Índice de columna (desde 1) → formato numérico
     * @param  int[]  $sumar  Columnas (desde 1) con total
     * @param  int[]  $anchos
     */
    public static function libro(string $titulo, string $subtitulo, array $encabezados, iterable $filas, array $formatos, array $sumar, array $anchos): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $titulo), 0, 31));
        $ultima = Coordinate::stringFromColumnIndex(count($encabezados));

        $hoja->setCellValue('A1', $titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $hoja->setCellValue('A2', $subtitulo.' · Generado el '.now('America/Mexico_City')->format('d/m/Y H:i'));
        $hoja->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        $hoja->fromArray($encabezados, null, 'A4');
        $hoja->getStyle("A4:{$ultima}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '7A1F3D']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $renglon = 5;
        foreach ($filas as $fila) {
            foreach (array_values($fila) as $i => $valor) {
                $celda = Coordinate::stringFromColumnIndex($i + 1).$renglon;
                // Lo que no es importe va como texto (números económicos, folios, claves).
                isset($formatos[$i + 1]) || $valor === null
                    ? $hoja->setCellValue($celda, $valor)
                    : $hoja->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
            }
            $renglon++;
        }

        $ultimoDato = $renglon - 1;
        if ($sumar) {
            $hoja->setCellValue("A{$renglon}", 'Total');
            foreach ($sumar as $columna) {
                $col = Coordinate::stringFromColumnIndex($columna);
                $hoja->setCellValue("{$col}{$renglon}", $ultimoDato >= 5 ? "=SUM({$col}5:{$col}{$ultimoDato})" : 0);
            }
            $hoja->getStyle("A{$renglon}:{$ultima}{$renglon}")->getFont()->setBold(true);
            $hoja->getStyle("A{$renglon}:{$ultima}{$renglon}")->getBorders()->getTop()->setBorderStyle('thin');
        }
        foreach ($formatos as $columna => $formato) {
            $col = Coordinate::stringFromColumnIndex($columna);
            $hoja->getStyle("{$col}5:{$col}{$renglon}")->getNumberFormat()->setFormatCode($formato);
        }
        foreach ($anchos as $i => $ancho) {
            $hoja->getColumnDimensionByColumn($i + 1)->setWidth($ancho);
        }
        $hoja->freezePane('A5');
        if ($ultimoDato >= 5) {
            $hoja->setAutoFilter("A4:{$ultima}{$ultimoDato}");
        }

        return $libro;
    }
}
