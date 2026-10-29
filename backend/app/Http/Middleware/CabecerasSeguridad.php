<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en las respuestas de la API: sin caché de datos (son de usuarios autenticados), sin
 * incrustarse en marcos, sin adivinar el tipo de contenido y, por HTTPS, solo HTTPS (HSTS).
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        if (! $h->has('Cache-Control') || ! str_contains((string) $h->get('Cache-Control'), 'no-store')) {
            $h->set('Cache-Control', 'no-store, private');
        }
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $h->remove('X-Powered-By');

        return $response;
    }
}
