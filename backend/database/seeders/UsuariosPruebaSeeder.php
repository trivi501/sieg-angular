<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios de prueba para desarrollo local (base sieg_angular). No correr en producción.
 *
 *   php artisan db:seed --class=UsuariosPruebaSeeder
 *
 * - admin.prueba@sieg.test     → Super Admin (ve todo el menú de Administración)
 * - solicitante.prueba@sieg.test → Área solicitante (no ve Administración)
 *
 * Contraseña de ambos: Prueba-SIEG-2026
 */
class UsuariosPruebaSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->isProduction(), 403, 'Seeder solo para desarrollo.');

        $usuarios = [
            'admin.prueba@sieg.test' => ['name' => 'ADMIN DE PRUEBA', 'rol' => 'Super Admin'],
            'solicitante.prueba@sieg.test' => ['name' => 'SOLICITANTE DE PRUEBA', 'rol' => 'Área solicitante'],
        ];

        foreach ($usuarios as $email => $datos) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $datos['name'], 'password' => Hash::make('Prueba-SIEG-2026')],
            );
            $user->syncRoles([$datos['rol']]);
        }
    }
}
