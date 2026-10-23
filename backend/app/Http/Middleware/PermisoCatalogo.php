<?php

namespace App\Http\Middleware;

use App\Catalogos\Catalogo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `catalogo:<accion>` — exige el permiso "{prefijo del catálogo}-<accion>" del catálogo de la ruta,
 * porque las rutas de /catalogos/{catalogo} son las mismas para todos los catálogos.
 */
class PermisoCatalogo
{
    public function handle(Request $request, Closure $next, string $accion): Response
    {
        $catalogo = $request->route('catalogo');

        abort_unless($catalogo instanceof Catalogo && $catalogo->disponible(), 404);
        abort_unless($request->user()?->checkPermissionTo("{$catalogo->prefijoPermiso()}-{$accion}"), 403);

        return $next($request);
    }
}
