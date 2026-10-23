<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comprime con gzip las respuestas JSON grandes de la API (catálogos, opciones de requisición, presupuesto)
 * cuando el navegador lo acepta. Además de aligerar la red, evita que `php artisan serve` en Windows corte
 * respuestas de más de ~255 KB (el cliente recibía JSON truncado y la pantalla se quedaba en "Cargando").
 */
class ComprimirJson
{
    /** Por debajo de esto no vale la pena comprimir. */
    private const MINIMO = 2048;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $contenido = $response instanceof JsonResponse ? $response->getContent() : false;
        if ($contenido === false || strlen($contenido) < self::MINIMO
            || ! str_contains((string) $request->header('Accept-Encoding'), 'gzip')
            || $response->headers->has('Content-Encoding')) {
            return $response;
        }

        $comprimido = gzencode($contenido, 6);
        if ($comprimido === false) {
            return $response;
        }

        $response->setContent($comprimido);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($comprimido));
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept-Encoding', ', '));

        return $response;
    }
}
