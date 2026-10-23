<?php

namespace App\Console\Commands;

use App\Catalogos\Catalogos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Sync all module permissions and assign them to Super Admin and Admin';

    public function handle(): int
    {
        $permissions = [
            // Logs
            'logs-view',

            // Support Tickets
            'tickets-index', 'tickets-create', 'tickets-update',

            // Presupuesto
            'presupuesto-index', 'presupuesto-create', 'presupuesto-edit', 'presupuesto-import', 'presupuesto-delete', 'presupuesto-correr-saldos',

            // Ejercicio y control del gasto (solo Admin y Control Presupuestal)
            'gasto-index',

            // Bandeja de Recursos Materiales (Admin, Control Presupuestal y Recursos Materiales)
            'recursos-materiales-index',

            // Requisiciones
            'requisiciones-index', 'requisiciones-create', 'requisiciones-edit', 'requisiciones-delete', 'requisiciones-suficiencia',
            'requisiciones-clc', // el área genera la CLC cuando la orden de compra ya tiene factura
            'requisiciones-extemporaneas', // Subdirección Administrativa: autoriza las capturadas fuera del periodo
            'requisiciones-fecha', // Recursos Materiales / Control Presupuestal: cambia la fecha de cualquier requisición
            'requisiciones-cotizar', // Recursos Materiales: recibe la requisición, inicia y edita la cotización
            'requisiciones-devolver', // Recursos Materiales regresa la requisición al área (p. ej. sin stock) para que la modifique
            'requisiciones-cancelar', // cancela cualquier requisición antes de la orden de compra

            // Órdenes de compra (Recursos Materiales)
            'ordenes-compra-create', 'ordenes-compra-pagar', 'ordenes-compra-fecha',
            'ordenes-compra-verificar-sat', // vuelve a consultar en el SAT si la factura sigue vigente
            'ordenes-compra-refacturar', // Admin: el proveedor volvió a facturar (cancela la CLC; al cargar el nuevo XML se genera otra)
            'ordenes-compra-cancelar', // cancela la orden de compra (antes, su CLC); después sigue la requisición

            // Trámite de CLC: ver la bandeja y actuar como cada área (el pago es ordenes-compra-pagar).
            // Almacén: ver bandeja e inventario, registrar entradas y salidas; RM asigna la orden de compra al almacén.
            'almacen-index', 'almacen-entradas', 'almacen-salidas', 'ordenes-compra-almacen',
            'vehiculos-mantenimiento', // taller: recibir vehículos, hacer su requisición (a nombre de la secretaría de resguardo) y entregarlos
            'facturas-index', // pestaña Facturas: CFDI cargados, validación en el SAT y su trámite
            'clc-index', 'clc-recursos-materiales', 'clc-control-presupuestal', 'clc-sindicatura', 'clc-egresos',
            'clc-cancelar', // cancela la CLC según el paso: el área hasta que la recibe RM, RM hasta que la recibe CP, CP hasta que la recibe Sindicatura (Admin siempre)

            // Modificaciones presupuestales
            'modificaciones-index', 'modificaciones-create', 'modificaciones-autorizar',

            // Usuarios, roles y permisos (settings)
            'users-index', 'users-create', 'users-edit', 'users-delete',
            'roles-index', 'roles-create', 'roles-edit', 'roles-delete',
            'permisos-index', 'permisos-create', 'permisos-edit', 'permisos-delete',

            // Bitácora de auditoría
            'auditoria-index',

            // Catálogos generales (incluye proveedores): {prefijo}-index|create|edit|delete|import
            // por cada catálogo de App\Catalogos\Catalogos.
            ...Catalogos::permisos(),
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Nombre legible y categoría de los permisos de catálogos para la pantalla de Roles.
        // (`categoria` no la crea ninguna migración: existe en MySQL pero no en SQLite de pruebas.)
        $acciones = ['index' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Dar de baja', 'import' => 'Importar'];
        $conCategoria = Schema::hasColumn('permissions', 'categoria');

        foreach (Catalogos::todos() as $catalogo) {
            foreach ($acciones as $accion => $verbo) {
                Permission::where('name', "{$catalogo->prefijoPermiso()}-{$accion}")
                    // Sin nombre, o con el que puso este comando (se actualiza si cambió el título del catálogo).
                    ->where(fn ($q) => $q->whereNull('nombre_mostrar')->orWhere('nombre_mostrar', 'like', "{$verbo}: %"))
                    ->update(['nombre_mostrar' => "{$verbo}: {$catalogo->titulo}", ...($conCategoria ? ['categoria' => 'Catálogos'] : [])]);
            }
        }

        $this->info(count($permissions).' permissions synced.');

        // Solo los roles administradores reciben todo; los demás (Área, Recursos Materiales,
        // Jefe de Control Presupuestal…) se administran desde Administración > Roles.
        foreach (['Super Admin', 'Admin'] as $rol) {
            Role::findOrCreate($rol, 'web')->syncPermissions($permissions);
            $this->info("{$rol}: all permissions assigned.");
        }

        return Command::SUCCESS;
    }
}
