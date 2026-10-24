<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;

/**
 * Lee un CFDI 3.3 / 4.0 completo (sin depender del namespace de la versión): datos del comprobante, emisor,
 * receptor, timbre, impuestos (IVA trasladado e ISR retenido) y sus conceptos, para mostrar el contenido.
 */
class Cfdi
{
    /**
     * @return array{tipo: ?string, uuid: ?string, serie: ?string, folio: ?string, fecha: ?string, rfc_emisor: ?string,
     *   nombre_emisor: ?string, rfc_receptor: ?string, subtotal: float, descuento: float, iva: float, isr: float, total: float,
     *   sello: ?string, conceptos: array<int, array<string, mixed>>}
     */
    public static function leer(string $contenido): array
    {
        $dom = new DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($contenido, LIBXML_NONET);
        libxml_use_internal_errors($previo);

        $xpath = $ok ? new DOMXPath($dom) : null;
        $comprobante = $xpath?->query('/*[local-name()="Comprobante"]')->item(0);
        if (! $comprobante) {
            throw ValidationException::withMessages(['xml' => 'El archivo no es un CFDI válido (no se encontró el nodo Comprobante).']);
        }

        $nodo = fn (string $ruta) => $xpath->query($ruta)->item(0);
        $attr = fn ($n, string $nombre) => $n && $n->hasAttribute($nombre) ? trim($n->getAttribute($nombre)) : null;
        $num = fn ($n, string $nombre) => is_numeric($attr($n, $nombre)) ? round((float) $attr($n, $nombre), 2) : 0.0;

        $total = $attr($comprobante, 'Total');
        if ($total === null || ! is_numeric($total)) {
            throw ValidationException::withMessages(['xml' => 'El CFDI no trae el atributo Total.']);
        }

        $emisor = $nodo('/*[local-name()="Comprobante"]/*[local-name()="Emisor"]');
        $receptor = $nodo('/*[local-name()="Comprobante"]/*[local-name()="Receptor"]');
        $timbre = $nodo('//*[local-name()="TimbreFiscalDigital"]');

        // Impuestos del comprobante (los de cada concepto son su desglose): IVA (002) trasladado e ISR (001) retenido.
        $iva = 0.0;
        foreach ($xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Impuestos"]/*[local-name()="Traslados"]/*[local-name()="Traslado"]') as $t) {
            if ($attr($t, 'Impuesto') === '002') {
                $iva += $num($t, 'Importe');
            }
        }
        $isr = 0.0;
        foreach ($xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Impuestos"]/*[local-name()="Retenciones"]/*[local-name()="Retencion"]') as $r) {
            if ($attr($r, 'Impuesto') === '001') {
                $isr += $num($r, 'Importe');
            }
        }

        $conceptos = [];
        foreach ($xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Conceptos"]/*[local-name()="Concepto"]') as $c) {
            // Impuestos del propio concepto: para repartir una factura entre partidas con el monto exacto de cada artículo.
            $ivaConcepto = 0.0;
            foreach ($xpath->query('./*[local-name()="Impuestos"]/*[local-name()="Traslados"]/*[local-name()="Traslado"]', $c) as $t) {
                if ($attr($t, 'Impuesto') === '002') {
                    $ivaConcepto += $num($t, 'Importe');
                }
            }
            $isrConcepto = 0.0;
            foreach ($xpath->query('./*[local-name()="Impuestos"]/*[local-name()="Retenciones"]/*[local-name()="Retencion"]', $c) as $r) {
                if ($attr($r, 'Impuesto') === '001') {
                    $isrConcepto += $num($r, 'Importe');
                }
            }
            $conceptos[] = [
                'cantidad' => is_numeric($attr($c, 'Cantidad')) ? (float) $attr($c, 'Cantidad') : null,
                'unidad' => $attr($c, 'Unidad') ?? $attr($c, 'ClaveUnidad'),
                'clave' => $attr($c, 'ClaveProdServ'),
                'descripcion' => $attr($c, 'Descripcion'),
                'valor_unitario' => $num($c, 'ValorUnitario'),
                'descuento' => $num($c, 'Descuento'),
                'importe' => $num($c, 'Importe'),
                'iva' => round($ivaConcepto, 2),
                'isr' => round($isrConcepto, 2),
            ];
        }

        // CFDI sin impuestos por concepto (versiones viejas): el IVA y el ISR del comprobante se reparten
        // en proporción a la base de cada concepto (el último lleva el redondeo).
        $base = array_sum(array_map(fn ($c) => $c['importe'] - $c['descuento'], $conceptos));
        foreach (['iva' => $iva, 'isr' => $isr] as $campo => $totalImpuesto) {
            if ($totalImpuesto > 0 && $base > 0 && array_sum(array_column($conceptos, $campo)) == 0) {
                $repartido = 0.0;
                foreach ($conceptos as $i => &$c) {
                    $c[$campo] = $i === count($conceptos) - 1
                        ? round($totalImpuesto - $repartido, 2)
                        : round($totalImpuesto * ($c['importe'] - $c['descuento']) / $base, 2);
                    $repartido += $c[$campo];
                }
                unset($c);
            }
        }

        return [
            'tipo' => $attr($comprobante, 'TipoDeComprobante'),
            'uuid' => $attr($timbre, 'UUID') ? mb_strtoupper($attr($timbre, 'UUID')) : null,
            'serie' => $attr($comprobante, 'Serie'),
            'folio' => $attr($comprobante, 'Folio'),
            'fecha' => $attr($comprobante, 'Fecha') ? date('Y-m-d H:i:s', strtotime($attr($comprobante, 'Fecha'))) : null,
            'rfc_emisor' => $attr($emisor, 'Rfc') ? mb_strtoupper($attr($emisor, 'Rfc')) : null,
            'nombre_emisor' => $attr($emisor, 'Nombre'),
            'rfc_receptor' => $attr($receptor, 'Rfc') ? mb_strtoupper($attr($receptor, 'Rfc')) : null,
            'subtotal' => $num($comprobante, 'SubTotal'),
            'descuento' => $num($comprobante, 'Descuento'),
            'iva' => round($iva, 2),
            'isr' => round($isr, 2),
            'total' => round((float) $total, 2),
            'sello' => $attr($comprobante, 'Sello'),
            'conceptos' => $conceptos,
        ];
    }
}
