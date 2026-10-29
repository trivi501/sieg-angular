<?php

namespace App\Http\Middleware;

use App\Support\Inactividad;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renueva la actividad del token en cada llamada del usuario (no en las de segundo plano). Si el token ya venció
 * por inactividad, Sanctum ni siquiera lo acepta (AppServiceProvider): la llamada responde 401.
 */
class RegistrarActividad
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ! $request->hasHeader(Inactividad::CABECERA_SEGUNDO_PLANO)) {
            Inactividad::renovar($token);
        }

        return $response;
    }
}
