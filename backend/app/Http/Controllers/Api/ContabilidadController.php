<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdenCompra;
use App\Models\TramiteServicioFactura;
use App\Services\ExcelTabla;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reporte mensual del ISR retenido (Departamento de Contabilidad; nuevo, SIEG no lo tenía): las facturas con ISR
 * retenido de las CLC pagadas en el mes (las retenciones se enteran en el mes en que se pagan) o, a elegir, de las
 * CLC emitidas en el mes. Cada factura: la de la orden de compra o, si la orden salió de un trámite (servicios,
 * fondo, combustible o contrato), las del trámite. Con resumen por RFC del emisor.
 */
class ContabilidadController extends Controller
{
    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function isr(Request $request): JsonResponse
    {
        [$filas, $filtros] = $this->datos($request);

        return response()->json([
            'facturas' => $filas,
            'por_rfc' => $this->porRfc($filas),
            'totales' => ['facturas' => $filas->count(), 'base' => round($filas->sum('base'), 2), 'isr' => round($filas->sum('isr'), 2), 'total' => round($filas->sum('total'), 2)],
            'filtros' => $filtros,
        ]);
    }

    public function isrExcel(Request $request)
    {
        [$filas, $filtros] = $this->datos($request);
        $formato = '#,##0.00;[Red]-#,##0.00';
        $libro = ExcelTabla::libro("ISR retenido de {$filtros['nombre_mes']}", $filtros['descripcion'],
            ['RFC del emisor', 'Emisor', 'UUID', 'Serie y folio', 'Fecha de la factura', 'Base', 'IVA', 'ISR retenido', 'Total', 'CLC', 'Orden', 'Fecha de pago', 'Transferencia / cheque'],
            $filas->map(fn ($f) => [$f['rfc'], $f['emisor'], $f['uuid'], $f['serie_folio'], $f['fecha'] ? date('d/m/Y', strtotime($f['fecha'])) : null,
                $f['base'], $f['iva'], $f['isr'], $f['total'], $f['clc'], $f['orden'], $f['pago_fecha'] ? date('d/m/Y', strtotime($f['pago_fecha'])) : null, $f['pago']]),
            [6 => $formato, 7 => $formato, 8 => $formato, 9 => $formato], [6, 7, 8, 9], [15, 36, 38, 14, 12, 14, 13, 14, 14, 15, 14, 12, 16]);

        // Segunda hoja: resumen por RFC.
        $resumen = ExcelTabla::libro('Resumen por RFC', $filtros['descripcion'], ['RFC', 'Emisor', 'Facturas', 'Base', 'ISR retenido'],
            $this->porRfc($filas)->map(fn ($r) => [$r['rfc'], $r['emisor'], $r['facturas'], $r['base'], $r['isr']]),
            [3 => '#,##0', 4 => $formato, 5 => $formato], [3, 4, 5], [15, 40, 10, 16, 16])->getActiveSheet();
        $libro->addExternalSheet($resumen);

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            "isr_retenido_{$filtros['mes']}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: array<string, string>} */
    private function datos(Request $request): array
    {
        $f = $request->validate(['mes' => ['nullable', 'regex:/^\d{4}-\d{2}$/'], 'base' => 'nullable|in:pago,clc']);
        $mes = CarbonImmutable::createFromFormat('!Y-m', $f['mes'] ?? now('America/Mexico_City')->format('Y-m'));
        $porPago = ($f['base'] ?? 'pago') === 'pago';
        $campo = $porPago ? 'pago_fecha' : 'clc_fecha';

        $ordenes = OrdenCompra::query()->whereNotNull('clc_folio_completo')->where('clc_estado', '!=', 'cancelada')
            ->when($porPago, fn ($q) => $q->whereNotNull('pago_fecha'))
            ->whereBetween($campo, [$mes->startOfMonth()->toDateString(), $mes->endOfMonth()->toDateString()])
            ->where('xml_isr', '>', 0)
            ->with('proveedor:id,nombre,rfc', 'tesoreriaPago:id,numero')
            ->orderBy($campo)->orderBy('clc_folio')->get();
        $deTramites = TramiteServicioFactura::whereIn('tramite_servicio_id', $ordenes->pluck('tramite_servicio_id')->filter())->where('isr', '>', 0)
            ->orderBy('id')->get()->groupBy('tramite_servicio_id');

        $filas = $ordenes->flatMap(function (OrdenCompra $o) use ($deTramites) {
            $comun = [
                'clc' => $o->clc_folio_completo,
                'orden' => $o->folio_completo,
                'clc_fecha' => $o->clc_fecha?->toDateString(),
                'pago_fecha' => $o->pago_fecha?->toDateString(),
                'pago' => $o->tesoreriaPago?->numero ?? $o->transferencia_numero ?? $o->pago_referencia,
            ];
            if ($o->tramite_servicio_id) {
                return ($deTramites[$o->tramite_servicio_id] ?? collect())->map(fn (TramiteServicioFactura $x) => [
                    'rfc' => $x->rfc_emisor, 'emisor' => $x->nombre_emisor, 'uuid' => $x->uuid, 'serie_folio' => $x->serieFolio(), 'fecha' => $x->fecha?->toDateString(),
                    'base' => $x->base(), 'iva' => (float) $x->iva, 'isr' => (float) $x->isr, 'total' => (float) $x->total, ...$comun,
                ]);
            }
            $base = round((float) $o->xml_subtotal, 2);
            $total = round((float) $o->xml_total, 2);

            return [[
                'rfc' => $o->xml_rfc_emisor ?? $o->proveedor?->rfc, 'emisor' => $o->xml_nombre_emisor ?? $o->proveedor?->nombre, 'uuid' => $o->xml_uuid,
                'serie_folio' => trim(($o->xml_serie ?? '').' '.($o->xml_folio ?? '')) ?: null, 'fecha' => $o->xml_fecha?->toDateString(),
                // Sin el desglose de la factura: IVA = total + ISR − base.
                'base' => $base, 'iva' => round($total + (float) $o->xml_isr - $base, 2), 'isr' => round((float) $o->xml_isr, 2), 'total' => $total, ...$comun,
            ]];
        })->values();

        $nombreMes = self::MESES[$mes->month].' de '.$mes->year;

        return [$filas, [
            'mes' => $mes->format('Y-m'),
            'base' => $porPago ? 'pago' : 'clc',
            'nombre_mes' => $nombreMes,
            'descripcion' => ($porPago ? 'CLC pagadas en ' : 'CLC emitidas en ').$nombreMes.' (sin canceladas)',
        ]];
    }

    private function porRfc(Collection $filas): Collection
    {
        return $filas->groupBy('rfc')->map(fn ($g, $rfc) => [
            'rfc' => $rfc, 'emisor' => $g->first()['emisor'], 'facturas' => $g->count(),
            'base' => round($g->sum('base'), 2), 'isr' => round($g->sum('isr'), 2),
        ])->sortByDesc('isr')->values();
    }
}
