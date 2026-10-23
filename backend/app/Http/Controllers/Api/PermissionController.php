<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/** Permisos (SIEG: Settings\PermissionController), respondiendo JSON en vez de páginas Inertia. */
class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $permissions = Permission::query()
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('nombre_mostrar', 'like', '%'.$request->q.'%')))
            ->when($request->filled('categoria'), fn ($q) => $q->where('categoria', $request->categoria))
            ->orderBy('name')
            ->paginate(50)
            ->through(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'nombre_mostrar' => $p->nombre_mostrar,
                'categoria' => $p->categoria,
                'guard_name' => $p->guard_name,
            ]);

        return response()->json([
            ...$permissions->toArray(),
            'categorias' => Permission::whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria'),
        ]);
    }

    public function show(Permission $permission): JsonResponse
    {
        return response()->json($permission->only('id', 'name', 'nombre_mostrar', 'categoria', 'guard_name'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name',
            'nombre_mostrar' => 'nullable|string|max:255',
            'categoria' => 'nullable|string|max:100',
        ]);

        $permission = Permission::create([...$validated, 'guard_name' => 'web']);

        return response()->json(['message' => 'Permiso creado.', 'id' => $permission->id], 201);
    }

    public function update(Request $request, Permission $permission): JsonResponse
    {
        $validated = $request->validate([
            'name' => "required|string|max:255|unique:permissions,name,{$permission->id}",
            'nombre_mostrar' => 'nullable|string|max:255',
            'categoria' => 'nullable|string|max:100',
        ]);

        $permission->update($validated);

        return response()->json(['message' => 'Permiso actualizado.']);
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();

        return response()->json(['message' => 'Permiso eliminado.']);
    }
}
