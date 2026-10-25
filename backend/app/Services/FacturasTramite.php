<?php

namespace App\Services;

use App\Models\CombustibleCarga;
use App\Models\CombustibleFactura;
use App\Models\OrdenCompra;
use App\Models\TramiteServicio;
use App\Models\TramiteServicioFactura;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Facturas (XML) de los trámites: CFDI de ingreso timbrado, a nombre del Ayuntamiento, vigente en el SAT y no
 * cargado antes. Servicio y combustible: del mismo proveedor que las demás (se vincula por RFC). Fondo: de
 * cualquier proveedor y de hasta $2,000. Combustible: debe ser una de las facturas del Excel del proveedor en esa
 * bitácora, y sus tickets quedan ligados a ella; si todavía no hay Excel, queda "por asignar" (CombustibleFactura)
 * y se pasa a su bitácora al cargarlo.
 */
class FacturasTramite
{
    /** Diferencia tolerada entre el total de la factura y la suma de sus tickets del Excel. */
    public const TOLERANCIA_COMBUSTIBLE = 1.00;

    public function __construct(private EstadoCfdiSat $sat) {}

    /** @return array{factura: TramiteServicioFactura, message: string, aviso: string|null} */
    public function agregar(TramiteServicio $tramite, string $contenido, ?array $cfdi = null): array
    {
        $fondo = $tramite->esFondo();
        $cfdi ??= Cfdi::leer($contenido);

        $errores = [];
        if ($fondo && $cfdi['total'] > TramiteServicio::LIMITE_FACTURA_FONDO) {
            $errores[] = sprintf('La factura es por $%s: en un fondo revolvente cada factura puede ser de hasta $%s.',
                number_format($cfdi['total'], 2), number_format(TramiteServicio::LIMITE_FACTURA_FONDO, 2));
        }
        if (! $fondo && $tramite->proveedor_rfc && $cfdi['rfc_emisor'] !== $tramite->proveedor_rfc) {
            $errores[] = "Todas las facturas del trámite deben ser del mismo proveedor ({$tramite->proveedor_rfc}); esta la emite {$cfdi['rfc_emisor']}.";
        }
        $folio = self::folio($cfdi);
        if ($tramite->esCombustible() && ! $this->cargasPendientes($tramite, $folio)->exists()) {
            $errores[] = "La factura {$folio} no está en el Excel del proveedor de esta bitácora (o ya se subió).";
        }
        $estado = $this->verificar($cfdi, $errores);

        $ruta = "tramites-servicio/{$tramite->año}/{$tramite->folio_completo}_{$cfdi['uuid']}.xml";
        Storage::disk('local')->put($ruta, $contenido);
        try {
            $factura = DB::transaction(fn () => $this->guardar($tramite, $cfdi, $estado, $ruta));
        } catch (UniqueConstraintViolationException) {
            Storage::disk('local')->delete($ruta);
            throw ValidationException::withMessages(['xml' => "La factura {$cfdi['uuid']} ya se cargó."]);
        }

        return [
            'factura' => $factura,
            'message' => "Factura {$cfdi['uuid']} agregada (vigente en el SAT).",
            'aviso' => $this->avisos($tramite->refresh(), $factura, $estado['efos']),
        ];
    }

    /**
     * Combustible sin Excel todavía: la factura se verifica igual y queda por asignar hasta que un Excel diga de qué
     * secretaría es.
     */
    public function porAsignar(string $contenido, array $cfdi): CombustibleFactura
    {
        $folio = self::folio($cfdi);
        $errores = $folio === '' ? ["La factura {$cfdi['uuid']} no trae serie ni folio: no se podrá ligar con el Excel del proveedor."] : [];
        $estado = $this->verificar($cfdi, $errores);

        $ruta = "combustible/por-asignar/{$cfdi['uuid']}.xml";
        Storage::disk('local')->put($ruta, $contenido);
        try {
            return CombustibleFactura::create([
                'uuid' => $cfdi['uuid'],
                'folio' => $folio,
                'rfc_emisor' => $cfdi['rfc_emisor'],
                'nombre_emisor' => $cfdi['nombre_emisor'],
                'fecha' => $cfdi['fecha'],
                'total' => $cfdi['total'],
                'xml_path' => $ruta,
                'cfdi' => $cfdi,
                'sat_estado' => $estado['estado'],
                'sat_efos' => $estado['efos'],
                'sat_verificado_at' => now(),
                'user_id' => auth()->id(),
            ]);
        } catch (UniqueConstraintViolationException) {
            Storage::disk('local')->delete($ruta);
            throw ValidationException::withMessages(['xml' => "La factura {$cfdi['uuid']} ya se cargó."]);
        }
    }

    /**
     * Pasa a la bitácora las facturas por asignar que su Excel pide (por folio). Las de otro proveedor que el de la
     * bitácora se quedan por asignar.
     *
     * @return int Cuántas se asignaron
     */
    public function asignarPendientes(TramiteServicio $bitacora): int
    {
        $folios = CombustibleCarga::where('tramite_servicio_id', $bitacora->id)->whereNull('tramite_servicio_factura_id')->distinct()->pluck('factura_folio');
        $asignadas = 0;
        foreach (CombustibleFactura::whereIn('folio', $folios)->orderBy('id')->get() as $pendiente) {
            if ($bitacora->proveedor_rfc && $pendiente->rfc_emisor !== $bitacora->proveedor_rfc) {
                continue;
            }
            $this->guardar($bitacora, $pendiente->cfdi, ['estado' => $pendiente->sat_estado, 'efos' => $pendiente->sat_efos], $pendiente->xml_path, $pendiente->sat_verificado_at);
            $pendiente->delete();
            $bitacora->refresh();
            $asignadas++;
        }

        return $asignadas;
    }

    /** Folio comparable del CFDI (serie + folio, como en el Excel del proveedor). */
    public static function folio(array $cfdi): string
    {
        return CombustibleCarga::normalizarFolio(($cfdi['serie'] ?? '').($cfdi['folio'] ?? ''));
    }

    /** Si los tickets del Excel no suman lo mismo que la factura, el aviso (se puede continuar). */
    public function diferenciaTickets(TramiteServicioFactura $factura): ?string
    {
        $tickets = round((float) CombustibleCarga::where('tramite_servicio_factura_id', $factura->id)->sum('importe'), 2);
        $diferencia = round($factura->total - $tickets, 2);

        return abs($diferencia) > self::TOLERANCIA_COMBUSTIBLE
            ? sprintf('Los tickets de la factura %s suman $%s y la factura es por $%s (diferencia de $%s): revísalo con el proveedor.',
                $factura->serieFolio() ?? $factura->uuid, number_format($tickets, 2), number_format($factura->total, 2), number_format($diferencia, 2))
            : null;
    }

    /** Lo que todavía no se puede corregir (proveedor, EFOS, tickets que no cuadran), para avisar sin impedir. */
    public function avisos(TramiteServicio $tramite, TramiteServicioFactura $factura, bool $efos): ?string
    {
        $avisos = array_filter([
            $tramite->impedimentoProveedor(),
            $efos ? "Atención: el emisor {$factura->rfc_emisor} aparece en la lista de EFOS del SAT." : null,
            $tramite->esCombustible() ? $this->diferenciaTickets($factura) : null,
        ]);

        return $avisos ? implode(' ', $avisos) : null;
    }

    /**
     * Revisiones comunes (CFDI de ingreso timbrado, a nombre del Ayuntamiento, no cargado antes) más las que trae
     * quien llama, y luego el estado en el SAT. Lanza ValidationException con todo lo que falle.
     *
     * @return array{estado: string, efos: bool}
     */
    private function verificar(array $cfdi, array $errores): array
    {
        if (! $cfdi['uuid']) {
            $errores[] = 'El XML no trae timbre fiscal (UUID); no es un CFDI timbrado.';
        }
        if ($cfdi['tipo'] && $cfdi['tipo'] !== 'I') {
            $errores[] = "El CFDI es de tipo {$cfdi['tipo']}; solo se aceptan facturas de ingreso (tipo I).";
        }
        $receptor = (string) config('services.sat.rfc_receptor');
        if ($receptor && $cfdi['rfc_receptor'] && $cfdi['rfc_receptor'] !== $receptor) {
            $errores[] = "La factura está a nombre de {$cfdi['rfc_receptor']}, no del Ayuntamiento ({$receptor}).";
        }
        if ($cfdi['uuid']) {
            $errores = [...$errores, ...$this->duplicidades($cfdi['uuid'])];
        }
        if ($errores) {
            throw ValidationException::withMessages(['xml' => implode(' ', $errores)]);
        }

        $estado = $this->sat->consultar($cfdi['rfc_emisor'] ?? '', $cfdi['rfc_receptor'] ?? $receptor, $cfdi['total'], $cfdi['uuid'], $cfdi['sello']);
        if (! $estado) {
            throw ValidationException::withMessages(['xml' => ($this->sat->motivo ?? 'No se pudo verificar la factura en el SAT.').' La factura no se guardó.']);
        }
        if ($error = EstadoCfdiSat::error($estado, $cfdi['uuid'])) {
            throw ValidationException::withMessages(['xml' => $error]);
        }

        return $estado;
    }

    /** La factura en el trámite; en combustible, ligada a sus tickets. Va dentro de una transacción. */
    private function guardar(TramiteServicio $tramite, array $cfdi, array $estado, string $ruta, $verificadoAt = null): TramiteServicioFactura
    {
        $fondo = $tramite->esFondo();
        $factura = $tramite->facturas()->create([
            'xml_path' => $ruta,
            'uuid' => $cfdi['uuid'],
            'rfc_emisor' => $cfdi['rfc_emisor'],
            'nombre_emisor' => $cfdi['nombre_emisor'],
            'rfc_receptor' => $cfdi['rfc_receptor'],
            'serie' => $cfdi['serie'],
            'folio' => $cfdi['folio'],
            'fecha' => $cfdi['fecha'],
            'subtotal' => $cfdi['subtotal'],
            'descuento' => $cfdi['descuento'],
            'iva' => $cfdi['iva'],
            'ieps' => $cfdi['ieps'],
            'isr' => $cfdi['isr'],
            'total' => $cfdi['total'],
            // Fondo: cada artículo lleva luego su partida / proyecto / fuente.
            'conceptos' => $fondo
                ? array_map(fn ($a) => $a + ['partida' => null, 'proyecto' => null, 'fuente' => null], $cfdi['conceptos'])
                : $cfdi['conceptos'],
            'sat_estado' => $estado['estado'],
            'sat_verificado_at' => $verificadoAt ?? now(),
            // Por omisión, el concepto general del trámite (se puede cambiar por factura).
            'concepto' => $tramite->concepto,
        ]);
        if (! $fondo && ! $tramite->proveedor_rfc) {
            $tramite->update(['proveedor_rfc' => $cfdi['rfc_emisor'], 'proveedor_nombre' => $cfdi['nombre_emisor']]);
        }
        if ($tramite->esCombustible()) {
            $this->cargasPendientes($tramite, self::folio($cfdi))->update(['tramite_servicio_factura_id' => $factura->id]);
        }

        return $factura;
    }

    /** Tickets de la bitácora con ese folio de factura que todavía no tienen su XML. */
    private function cargasPendientes(TramiteServicio $tramite, string $folio)
    {
        return CombustibleCarga::where('tramite_servicio_id', $tramite->id)->where('factura_folio', $folio)->whereNull('tramite_servicio_factura_id');
    }

    /** Ya cargada en otro trámite, fondo o bitácora, por asignar, o en una orden de compra. */
    private function duplicidades(string $uuid): array
    {
        $errores = [];
        $otro = TramiteServicioFactura::with('tramite:id,folio_completo,estado')->where('uuid', $uuid)->first();
        if ($otro) {
            $errores[] = "La factura {$uuid} ya está en {$otro->tramite?->folio_completo}.";
        }
        if (CombustibleFactura::where('uuid', $uuid)->exists()) {
            $errores[] = "La factura {$uuid} ya se subió y está por asignar (espera el Excel del proveedor).";
        }
        $orden = OrdenCompra::where('xml_uuid', $uuid)->first(['folio_completo']);
        if ($orden) {
            $errores[] = "La factura {$uuid} ya está cargada en la orden de compra {$orden->folio_completo}.";
        }

        return $errores;
    }
}
