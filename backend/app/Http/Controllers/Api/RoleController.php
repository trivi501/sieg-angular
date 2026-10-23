<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
use App\Models\User;
use App\Support\ProteccionAdministradores;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Roles (SIEG: Settings\RoleController), respondiendo JSON en vez de páginas Inertia. */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::withCount('permissions')
            ->addSelect(['users_count' => DB::table('model_has_roles')->selectRaw('count(*)')
                ->whereColumn('role_id', 'roles.id')->where('model_type', User::class)])
            ->orderBy('name')->get()->map(fn ($role) => [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions_count' => $role->permissions_count,
            'users_count' => $role->users_count,
            'created_at' => $role->created_at?->toIso8601String(),
        ]);

        return response()->json($roles);
    }

    /** Todos los permisos, para la lista de casillas agrupada por categoría. */
    public function opciones(): JsonResponse
    {
        return response()->json([
            'permissions' => Permission::orderBy('categoria')->orderBy('name')->get(['id', 'name', 'nombre_mostrar', 'categoria']),
        ]);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->permissions()->pluck('id'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        if ($impedimento = ProteccionAdministradores::impedimentoRol($request->user(), null, $validated['permissions'] ?? [])) {
            return response()->json(['message' => $impedimento], 403);
        }

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);

        $this->sincronizarPermisos($role, $validated['permissions'] ?? []);

        return response()->json(['message' => 'Rol creado.', 'id' => $role->id], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => "required|string|max:255|unique:roles,name,{$role->id}",
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        if ($impedimento = ProteccionAdministradores::impedimentoRol($request->user(), $role, $validated['permissions'] ?? [])) {
            return response()->json(['message' => $impedimento], 403);
        }

        $role->update(['name' => $validated['name']]);

        $this->sincronizarPermisos($role, $validated['permissions'] ?? []);

        return response()->json(['message' => 'Rol actualizado.']);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        // Eliminar equivale a quitarle todos sus permisos.
        if ($impedimento = ProteccionAdministradores::impedimentoRol($request->user(), $role, [])) {
            return response()->json(['message' => $impedimento], 403);
        }

        $role->delete();

        return response()->json(['message' => 'Rol eliminado.']);
    }

    /** El cambio de permisos de un rol queda en la bitácora de auditoría (por nombre de permiso). */
    private function sincronizarPermisos(Role $role, array $permisos): void
    {
        $antes = $role->permissions()->pluck('name')->all();
        $role->permissions()->sync($permisos);
        $role->forgetCachedPermissions();

        Auditoria::relacion($role, 'permisos', $antes, $role->permissions()->pluck('name')->all());
    }
}
