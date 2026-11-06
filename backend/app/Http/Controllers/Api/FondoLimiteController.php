<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FondoSinLimite;
use App\Models\TramiteServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Menú "Quitar límite" (permiso fondos-sin-limite): quita el límite de $2,000 por factura a todos los fondos
 * revolventes durante 5 minutos, o lo restablece antes. Muestra el estado y las últimas veces que se quitó.
 */
class FondoLimiteController extends Controller
{
    public function show(): JsonResponse
    {
        $vigente = FondoSinLimite::vigente()?->load('usuario:id,name');

        return response()->json([
            'limite' => TramiteServicio::LIMITE_FACTURA_FONDO,
            'minutos' => FondoSinLimite::MINUTOS,
            'sin_limite_hasta' => $vigente?->hasta->toIso8601String(),
            'sin_limite_por' => $vigente?->usuario?->name,
            'historial' => FondoSinLimite::with('usuario:id,name', 'restablecio:id,name')->latest('id')->limit(15)->get()
                ->map(fn (FondoSinLimite $f) => [
                    'id' => $f->id,
                    'desde' => $f->created_at?->toIso8601String(),
                    'hasta' => $f->hasta->toIso8601String(),
                    'por' => $f->usuario?->name,
                    'restablecido_at' => $f->restablecido_at?->toIso8601String(),
                    'restablecido_por' => $f->restablecio?->name,
                ]),
        ]);
    }

    /** Quita el límite a todos los fondos por FondoSinLimite::MINUTOS minutos (si ya estaba quitado, vuelve a contar). */
    public function quitar(Request $request): JsonResponse
    {
        FondoSinLimite::vigente()?->update(['restablecido_at' => now(), 'restablecido_user_id' => $request->user()->id]);
        $nuevo = FondoSinLimite::create(['hasta' => now()->addMinutes(FondoSinLimite::MINUTOS), 'user_id' => $request->user()->id]);

        return response()->json(['message' => 'Límite de $'.number_format(TramiteServicio::LIMITE_FACTURA_FONDO, 2).' quitado a todos los fondos revolventes durante '
            .FondoSinLimite::MINUTOS.' minutos (hasta las '.$nuevo->hasta->timezone('America/Mexico_City')->format('H:i').'). Después vuelve solo.']);
    }

    public function restablecer(Request $request): JsonResponse
    {
        $vigente = FondoSinLimite::vigente();
        if (! $vigente) {
            return response()->json(['message' => 'El límite ya está vigente.'], 422);
        }
        $vigente->update(['restablecido_at' => now(), 'restablecido_user_id' => $request->user()->id]);

        return response()->json(['message' => 'Límite de $'.number_format(TramiteServicio::LIMITE_FACTURA_FONDO, 2).' por factura restablecido en todos los fondos.']);
    }
}
