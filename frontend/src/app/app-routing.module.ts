// angular import
import { NgModule } from '@angular/core';
import { RouterModule, Routes } from '@angular/router';

// Project import
import { AdminLayout } from './theme/layouts/admin-layout/admin-layout.component';
import { GuestLayoutComponent } from './theme/layouts/guest-layout/guest-layout.component';
import { authGuard, guestGuard, permissionGuard } from './core/auth/auth.guards';

const routes: Routes = [
  {
    path: '',
    component: AdminLayout,
    canActivate: [authGuard],
    canActivateChild: [permissionGuard],
    children: [
      {
        path: '',
        redirectTo: '/inicio',
        pathMatch: 'full'
      },
      {
        path: 'inicio',
        loadComponent: () => import('./pages/inicio/inicio.component').then((c) => c.InicioComponent)
      },
      {
        path: 'sin-acceso',
        loadComponent: () => import('./pages/sin-acceso/sin-acceso.component').then((c) => c.SinAccesoComponent)
      },
      // Requisiciones (SIEG: routes/web.php). Cada acción la vuelve a revisar el backend.
      {
        path: 'requisiciones',
        data: { permission: 'requisiciones-index' },
        loadComponent: () => import('./pages/requisiciones/requisiciones-lista.component').then((c) => c.RequisicionesListaComponent)
      },
      {
        path: 'requisiciones/nueva',
        data: { permission: 'requisiciones-create' },
        loadComponent: () => import('./pages/requisiciones/requisicion-form.component').then((c) => c.RequisicionFormComponent)
      },
      {
        path: 'requisiciones/gasto',
        data: { permission: 'gasto-index' },
        loadComponent: () => import('./pages/requisiciones/gasto.component').then((c) => c.GastoComponent)
      },
      {
        path: 'requisiciones/:id',
        data: { permission: 'requisiciones-index' },
        loadComponent: () => import('./pages/requisiciones/requisicion-detalle.component').then((c) => c.RequisicionDetalleComponent)
      },
      {
        path: 'requisiciones/:id/editar',
        data: { permission: ['requisiciones-edit', 'requisiciones-cotizar'] },
        loadComponent: () => import('./pages/requisiciones/requisicion-form.component').then((c) => c.RequisicionFormComponent)
      },
      {
        path: 'recursos-materiales',
        data: { permission: 'recursos-materiales-index', bandeja: 'recursos-materiales' },
        loadComponent: () => import('./pages/requisiciones/requisiciones-lista.component').then((c) => c.RequisicionesListaComponent)
      },
      {
        path: 'ordenes-compra',
        data: { permission: ['recursos-materiales-index', 'ordenes-compra-create', 'ordenes-compra-cancelar', 'ordenes-compra-pagar', 'ordenes-compra-verificar-sat'] },
        loadComponent: () => import('./pages/ordenes-compra/ordenes-compra.component').then((c) => c.OrdenesCompraComponent)
      },
      // Almacén conforme a la orden de compra.
      {
        path: 'almacen',
        data: { permission: 'almacen-index' },
        loadComponent: () => import('./pages/almacen/almacen-lista.component').then((c) => c.AlmacenListaComponent)
      },
      {
        path: 'almacen/inventario',
        data: { permission: 'almacen-index' },
        loadComponent: () => import('./pages/almacen/inventario.component').then((c) => c.InventarioComponent)
      },
      {
        path: 'almacen/:id',
        data: { permission: 'almacen-index' },
        loadComponent: () => import('./pages/almacen/almacen-orden.component').then((c) => c.AlmacenOrdenComponent)
      },
      // Mantenimiento vehicular (taller).
      {
        path: 'vehiculos/mantenimientos',
        data: { permission: 'vehiculos-mantenimiento' },
        loadComponent: () => import('./pages/vehiculos/mantenimientos.component').then((c) => c.MantenimientosComponent)
      },
      // Facturas (CFDI) de las órdenes de compra.
      {
        path: 'facturas',
        data: { permission: 'facturas-index' },
        loadComponent: () => import('./pages/facturas/facturas.component').then((c) => c.FacturasComponent)
      },
      // Trámite de CLC: cada acción revisa su permiso en el backend (TramiteClc).
      {
        path: 'clc',
        data: { permission: 'clc-index' },
        loadComponent: () => import('./pages/clc/clc.component').then((c) => c.ClcComponent)
      },
      // Modificaciones presupuestales.
      {
        path: 'modificaciones',
        data: { permission: 'modificaciones-index' },
        loadComponent: () => import('./pages/modificaciones/modificaciones-lista.component').then((c) => c.ModificacionesListaComponent)
      },
      {
        path: 'modificaciones/nueva',
        data: { permission: 'modificaciones-create' },
        loadComponent: () => import('./pages/modificaciones/modificacion-form.component').then((c) => c.ModificacionFormComponent)
      },
      {
        path: 'modificaciones/:id',
        data: { permission: 'modificaciones-index' },
        loadComponent: () => import('./pages/modificaciones/modificacion-detalle.component').then((c) => c.ModificacionDetalleComponent)
      },
      // Presupuesto (alta, edición e importación además exigen rol Admin en el backend).
      {
        path: 'presupuesto',
        data: { permission: 'presupuesto-index' },
        loadComponent: () => import('./pages/presupuesto/presupuesto-lista.component').then((c) => c.PresupuestoListaComponent)
      },
      {
        path: 'presupuesto/nueva',
        data: { permission: 'presupuesto-create' },
        loadComponent: () => import('./pages/presupuesto/presupuesto-form.component').then((c) => c.PresupuestoFormComponent)
      },
      {
        path: 'presupuesto/:id',
        data: { permission: 'presupuesto-index' },
        loadComponent: () => import('./pages/presupuesto/presupuesto-detalle.component').then((c) => c.PresupuestoDetalleComponent)
      },
      {
        path: 'presupuesto/:id/editar',
        data: { permission: 'presupuesto-edit' },
        loadComponent: () => import('./pages/presupuesto/presupuesto-form.component').then((c) => c.PresupuestoFormComponent)
      },
      // Catálogos generales: el permiso de cada uno lo revisa el backend (catalogo:<accion>).
      {
        path: 'catalogos',
        data: { permission: ['catalogos-*-index', 'proveedores-index'] },
        loadComponent: () => import('./pages/catalogos/catalogos-inicio.component').then((c) => c.CatalogosInicioComponent)
      },
      {
        path: 'catalogos/:slug',
        loadComponent: () => import('./pages/catalogos/catalogo.component').then((c) => c.CatalogoComponent)
      },
      // Tickets de soporte.
      {
        path: 'tickets',
        data: { permission: 'tickets-index' },
        loadComponent: () => import('./pages/tickets/tickets-lista.component').then((c) => c.TicketsListaComponent)
      },
      {
        path: 'tickets/nuevo',
        data: { permission: 'tickets-create' },
        loadComponent: () => import('./pages/tickets/ticket-nuevo.component').then((c) => c.TicketNuevoComponent)
      },
      {
        path: 'tickets/:id',
        data: { permission: 'tickets-index' },
        loadComponent: () => import('./pages/tickets/ticket-detalle.component').then((c) => c.TicketDetalleComponent)
      },
      // Bitácora de auditoría.
      {
        path: 'auditoria',
        data: { permission: 'auditoria-index' },
        loadComponent: () => import('./pages/auditoria/auditoria.component').then((c) => c.AuditoriaComponent)
      },
      // Administración (SIEG: routes/settings.php)
      {
        path: 'usuarios',
        data: { permission: 'users-index' },
        loadComponent: () => import('./pages/admin/usuarios/usuarios-lista.component').then((c) => c.UsuariosListaComponent)
      },
      {
        path: 'usuarios/nuevo',
        data: { permission: 'users-create' },
        loadComponent: () => import('./pages/admin/usuarios/usuario-form.component').then((c) => c.UsuarioFormComponent)
      },
      {
        path: 'usuarios/:id',
        data: { permission: 'users-edit' },
        loadComponent: () => import('./pages/admin/usuarios/usuario-form.component').then((c) => c.UsuarioFormComponent)
      },
      {
        path: 'roles',
        data: { permission: 'roles-index' },
        loadComponent: () => import('./pages/admin/roles/roles-lista.component').then((c) => c.RolesListaComponent)
      },
      {
        path: 'roles/nuevo',
        data: { permission: 'roles-create' },
        loadComponent: () => import('./pages/admin/roles/rol-form.component').then((c) => c.RolFormComponent)
      },
      {
        path: 'roles/:id',
        data: { permission: 'roles-edit' },
        loadComponent: () => import('./pages/admin/roles/rol-form.component').then((c) => c.RolFormComponent)
      },
      {
        path: 'permisos',
        data: { permission: 'permisos-index' },
        loadComponent: () => import('./pages/admin/permisos/permisos-lista.component').then((c) => c.PermisosListaComponent)
      }
    ]
  },
  {
    path: '',
    component: GuestLayoutComponent,
    children: [
      {
        path: 'login',
        canActivate: [guestGuard],
        loadComponent: () => import('./demo/pages/authentication/auth-login/auth-login.component').then((c) => c.AuthLoginComponent)
      }
    ]
  },
  { path: '**', redirectTo: '/inicio' }
];

@NgModule({
  imports: [RouterModule.forRoot(routes)],
  exports: [RouterModule]
})
export class AppRoutingModule {}
