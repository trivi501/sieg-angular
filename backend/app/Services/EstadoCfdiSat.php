<?php

namespace App\Services;

use App\Models\OrdenCompra;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Consulta el estado de un CFDI en el servicio público del SAT (el mismo de verificacfdi.facturaelectronica.sat.gob.mx):
 * si está Vigente, Cancelado o No encontrado, y si el emisor está en la lista de EFOS (empresas que facturan
 * operaciones simuladas). No requiere contrato ni llave.
 */
class EstadoCfdiSat
{
    public const URL = 'https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc';

    public const VIGENTE = 'Vigente';

    public const CANCELADO = 'Cancelado';

    public const NO_ENCONTRADO = 'No Encontrado';

    /**
     * Devuelve el estado o null si el SAT no respondió (servicio caído, sin internet): quien llama decide
     * dejar continuar.
     *
     * @return array{estado: string, estatus_cancelacion: string|null, es_cancelable: string|null, efos: bool, codigo: string|null}|null
     */
    public function consultar(string $rfcEmisor, string $rfcReceptor, float $total, string $uuid, ?string $sello = null): ?array
    {
        $expresion = $this->expresion($rfcEmisor, $rfcReceptor, $total, $uuid, $sello);
        $sobre = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">'
            .'<soapenv:Header/><soapenv:Body><tem:Consulta><tem:expresionImpresa>'
            .htmlspecialchars($expresion, ENT_XML1)
            .'</tem:expresionImpresa></tem:Consulta></soapenv:Body></soapenv:Envelope>';

        try {
            $respuesta = Http::withHeaders(['SOAPAction' => 'http://tempuri.org/IConsultaCFDIService/Consulta'])
                ->withBody($sobre, 'text/xml; charset=utf-8')
                ->connectTimeout(5)->timeout(15)
                ->post(self::URL);

            if (! $respuesta->successful()) {
                Log::warning("Consulta de CFDI {$uuid} al SAT: HTTP {$respuesta->status()}");

                return null;
            }

            return $this->leerRespuesta($respuesta->body());
        } catch (Throwable $e) {
            Log::warning("Consulta de CFDI {$uuid} al SAT falló: {$e->getMessage()}");

            return null;
        }
    }

    /** Consulta la factura ya cargada en la orden de compra (lee receptor y sello del XML guardado). */
    public function consultarOrden(OrdenCompra $orden): ?array
    {
        if (! $orden->xml_uuid) {
            return null;
        }

        $xml = $orden->xml_path && Storage::disk('local')->exists($orden->xml_path)
            ? self::datosDelXml(Storage::disk('local')->get($orden->xml_path)) : [];

        return $this->consultar(
            $orden->xml_rfc_emisor ?? '',
            $xml['rfc_receptor'] ?? '',
            (float) $orden->xml_total,
            $orden->xml_uuid,
            $xml['sello'] ?? null,
        );
    }

    /** Guarda el resultado en la orden (si el SAT respondió). */
    public static function guardar(OrdenCompra $orden, ?array $estado): void
    {
        if ($estado === null) {
            return;
        }

        $orden->update([
            'sat_estado' => $estado['estado'],
            'sat_estatus_cancelacion' => $estado['estatus_cancelacion'],
            'sat_efos' => $estado['efos'],
            'sat_verificado_at' => now(),
        ]);
    }

    /** Mensaje de rechazo por estado en el SAT (null si está vigente). */
    public static function error(array $sat, string $uuid): ?string
    {
        return match ($sat['estado']) {
            self::CANCELADO => "La factura {$uuid} está CANCELADA en el SAT. Pide al proveedor una factura vigente.",
            self::NO_ENCONTRADO => "La factura {$uuid} no existe en el SAT (no encontrada). Puede ser apócrifa o estar recién timbrada (el SAT puede tardar hasta 72 horas en registrarla); vuelve a intentar más tarde.",
            default => null,
        };
    }

    /**
     * RFC del receptor, sello (la expresión del SAT lleva sus últimos 8 caracteres) e ISR retenido del CFDI:
     * suma de las retenciones del comprobante con Impuesto 001 (ISR).
     *
     * @return array{rfc_receptor: string|null, sello: string|null, isr: float}
     */
    public static function datosDelXml(string $contenido): array
    {
        $dom = new DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($contenido, LIBXML_NONET);
        libxml_use_internal_errors($previo);
        if (! $ok) {
            return ['rfc_receptor' => null, 'sello' => null, 'isr' => 0.0];
        }

        $xpath = new DOMXPath($dom);
        $comprobante = $xpath->query('/*[local-name()="Comprobante"]')->item(0);
        $receptor = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Receptor"]')->item(0);

        // Solo las del comprobante (las de cada concepto son el desglose de las mismas).
        $isr = 0.0;
        foreach ($xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Impuestos"]/*[local-name()="Retenciones"]/*[local-name()="Retencion"]') as $retencion) {
            if ($retencion->getAttribute('Impuesto') === '001') {
                $isr += (float) $retencion->getAttribute('Importe');
            }
        }

        return [
            'rfc_receptor' => $receptor?->getAttribute('Rfc') ?: null,
            'sello' => $comprobante?->getAttribute('Sello') ?: null,
            'isr' => round($isr, 2),
        ];
    }

    /** Expresión impresa de CFDI 3.3 / 4.0 (la del código QR). */
    private function expresion(string $rfcEmisor, string $rfcReceptor, float $total, string $uuid, ?string $sello): string
    {
        $tt = rtrim(number_format($total, 6, '.', ''), '0');
        if (str_ends_with($tt, '.')) {
            $tt .= '0';
        }

        return 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx'
            .'?id='.mb_strtoupper($uuid)
            .'&re='.mb_strtoupper($rfcEmisor)
            .'&rr='.mb_strtoupper($rfcReceptor)
            .'&tt='.$tt
            .'&fe='.($sello ? substr($sello, -8) : '');
    }

    private function leerRespuesta(string $cuerpo): ?array
    {
        $dom = new DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($cuerpo, LIBXML_NONET);
        libxml_use_internal_errors($previo);
        if (! $ok) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $valor = fn (string $nodo) => ($n = $xpath->query("//*[local-name()=\"{$nodo}\"]")->item(0)) ? trim($n->textContent) : null;

        $estado = $valor('Estado');
        if ($estado === null || $estado === '') {
            return null;
        }

        return [
            // El SAT responde "Vigente", "Cancelado" o "No Encontrado".
            'estado' => match (mb_strtolower($estado)) {
                'vigente' => self::VIGENTE,
                'cancelado' => self::CANCELADO,
                default => self::NO_ENCONTRADO,
            },
            'estatus_cancelacion' => $valor('EstatusCancelacion') ?: null,
            'es_cancelable' => $valor('EsCancelable') ?: null,
            // 200 = el emisor no está en la lista de EFOS; 100 = sí está.
            'efos' => $valor('ValidacionEFOS') === '100',
            'codigo' => $valor('CodigoEstatus') ?: null,
        ];
    }
}
