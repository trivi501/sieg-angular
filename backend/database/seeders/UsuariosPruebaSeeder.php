<?php

namespace Database\Seeders;

use App\Models\EgresoUnidadAdministrativa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios de prueba para desarrollo local. No correr en producción. Requiere las migraciones aplicadas.
 *
 *   php artisan db:seed --class=UsuariosPruebaSeeder
 *
 * - admin.prueba@sieg.test       → Super Admin (ve todo el menú de Administración)
 * - solicitante.prueba@sieg.test → Área solicitante de la secretaría 210 (captura servicios y fondos y llena sus
 *                                  bitácoras de combustible; no ve Administración)
 * - materiales.prueba@sieg.test  → Recursos Materiales (valida trámites de servicios y bitácoras de combustible)
 * - combustible.prueba@sieg.test → Control de combustible (sube las facturas y el Excel del proveedor, todas las secretarías)
 * - oic.prueba@sieg.test         → Órgano Interno de Control (padrón de proveedores: expediente y cédulas)
 *
 * Contraseña de todos: Prueba-SIEG-2026
 */
class UsuariosPruebaSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->isProduction(), 403, 'Seeder solo para desarrollo.');

        // Los roles y sus permisos son los del sistema (también en producción).
        $this->call(RolesSistemaSeeder::class);

        $usuarios = [
            'admin.prueba@sieg.test' => ['name' => 'ADMIN DE PRUEBA', 'rol' => 'Super Admin'],
            'solicitante.prueba@sieg.test' => ['name' => 'SOLICITANTE DE PRUEBA', 'rol' => 'Área solicitante', 'clave' => '210'],
            'materiales.prueba@sieg.test' => ['name' => 'RECURSOS MATERIALES DE PRUEBA', 'rol' => 'Recursos Materiales'],
            'combustible.prueba@sieg.test' => ['name' => 'CONTROL DE COMBUSTIBLE DE PRUEBA', 'rol' => 'Control de combustible'],
            'oic.prueba@sieg.test' => ['name' => 'ÓRGANO INTERNO DE CONTROL DE PRUEBA', 'rol' => 'Órgano Interno de Control'],
        ];

        foreach ($usuarios as $email => $datos) {
            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $datos['name'], 'password' => Hash::make('Prueba-SIEG-2026'),
                    // Secretaría del usuario (por su clave), para lo que cada área ve solo de la suya.
                    'id_cat_egreso_unidad_administrativa' => isset($datos['clave'])
                        ? EgresoUnidadAdministrativa::where('clave', $datos['clave'])->orderByDesc('activo')->value('id_cat_egreso_unidad_administrativa')
                        : null],
            );
            $user->syncRoles([$datos['rol']]);
        }
    }
}
