<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Evita que quien administra usuarios o roles sin ser Admin se dé más permisos de los que tiene
 * (p. ej. Control Presupuestal tiene users-edit y roles-edit): no puede tocar a los administradores
 * ni sus roles, asignarlos, ni dar a un rol permisos que él mismo no tiene. En SIEG no había este freno.
 */
class ProteccionAdministradores
{
    public const ROLES = ['Super Admin', 'Admin'];

    public static function esAdmin(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    /** IDs de los roles de administrador. */
    public static function idsRoles(): array
    {
        return Role::whereIn('name', self::ROLES)->pluck('id')->all();
    }

    /** Motivo por el que $actor no puede editar, eliminar o asignar roles a $usuario; null si puede. */
    public static function impedimentoUsuario(User $actor, User $usuario, array $rolesNuevos = []): ?string
    {
        if (self::esAdmin($actor)) {
            return null;
        }
        if ($usuario->exists && self::esAdmin($usuario)) {
            return 'Solo un administrador puede modificar a otro administrador.';
        }
        $nuevos = array_map('intval', $rolesNuevos);
        if (array_intersect($nuevos, self::idsRoles())) {
            return 'Solo un administrador puede asignar los roles Admin o Super Admin.';
        }
        // Tampoco puede asignar (ni quitar) un rol con permisos que él no tiene, p. ej. darse "Egresos" para pagar.
        $actuales = $usuario->exists ? $usuario->roles()->pluck('id')->all() : [];
        $cambiados = array_merge(array_diff($nuevos, $actuales), array_diff($actuales, $nuevos));
        $propios = $actor->getAllPermissions()->pluck('id')->all();
        foreach (Role::with('permissions:id')->whereIn('id', $cambiados)->get() as $rol) {
            if (array_diff($rol->permissions->pluck('id')->all(), $propios)) {
                return "No puedes asignar ni quitar el rol {$rol->name}: tiene permisos que tú no tienes.";
            }
        }

        return null;
    }

    /** Motivo por el que $actor no puede crear, editar o eliminar $rol con esos permisos; null si puede. */
    public static function impedimentoRol(User $actor, ?Role $rol, array $permisos = []): ?string
    {
        if (self::esAdmin($actor)) {
            return null;
        }
        if ($rol && in_array($rol->name, self::ROLES, true)) {
            return 'Solo un administrador puede modificar los roles Admin o Super Admin.';
        }
        // Solo puede agregar o quitar permisos que él mismo tiene; los demás del rol quedan como están.
        $propios = $actor->getAllPermissions()->pluck('id')->all();
        $actuales = $rol ? $rol->permissions()->pluck('id')->all() : [];
        $nuevos = array_map('intval', $permisos);
        $cambiados = array_merge(array_diff($nuevos, $actuales), array_diff($actuales, $nuevos));
        if (array_diff($cambiados, $propios)) {
            return 'Solo puedes agregar o quitar permisos que tú tienes.';
        }

        return null;
    }
}
