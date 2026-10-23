<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login con token de Sanctum (Bearer). Equivale a Fortify en SIEG: login por correo en minúsculas,
 * 5 intentos por minuto por correo e IP, y cada acceso queda en la bitácora de auditoría.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = Str::lower($datos['email']);
        $clave = Str::transliterate($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($clave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Intenta de nuevo en '.RateLimiter::availableIn($clave).' segundos.',
            ]);
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($datos['password'], $user->password)) {
            RateLimiter::hit($clave);
            Auditoria::acceso('acceso fallido', $user, 'Correo: '.$email);

            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }

        RateLimiter::clear($clave);
        Auditoria::acceso('inicio de sesión', $user);

        return response()->json([
            'token' => $user->createToken('angular')->plainTextToken,
            'user' => $this->perfil($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->perfil($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        Auditoria::acceso('cierre de sesión', $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /** Lo que Angular necesita para menú, guards y encabezado (en SIEG eran props compartidos de Inertia). */
    private function perfil(User $user): array
    {
        $user->load('roles', 'unidadAdministrativa');

        return [
            'id' => $user->id,
            'name' => $user->nombreConTitulo(),
            'email' => $user->email,
            'cargo' => $user->cargo,
            'unidad' => $user->unidadAdministrativa?->nombre,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
