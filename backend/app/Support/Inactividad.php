<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * La sesión (token de Sanctum) se cierra tras N minutos sin actividad del usuario (config sanctum.inactividad,
 * 30 por omisión). La actividad es actividad_at; las consultas en segundo plano (cabecera X-Segundo-Plano) no la
 * renuevan.
 */
class Inactividad
{
    public const CABECERA_SEGUNDO_PLANO = 'X-Segundo-Plano';

    /** Para no escribir en la base en cada llamada: solo si pasó al menos este tiempo desde la última. */
    private const RENOVAR_CADA_SEGUNDOS = 30;

    public static function minutos(): int
    {
        return max(1, (int) config('sanctum.inactividad', 30));
    }

    public static function ultima(PersonalAccessToken $token): ?CarbonInterface
    {
        return $token->actividad_at ? Carbon::parse($token->actividad_at) : ($token->last_used_at ?? $token->created_at);
    }

    public static function vencido(PersonalAccessToken $token): bool
    {
        $ultima = self::ultima($token);

        return $ultima !== null && $ultima->lt(now()->subMinutes(self::minutos()));
    }

    public static function renovar(PersonalAccessToken $token): void
    {
        $ultima = $token->actividad_at ? Carbon::parse($token->actividad_at) : null;
        if (! $ultima || $ultima->lt(now()->subSeconds(self::RENOVAR_CADA_SEGUNDOS))) {
            $token->forceFill(['actividad_at' => now()])->saveQuietly();
        }
    }
}
