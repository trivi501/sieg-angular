export interface NavigationItem {
  id: string;
  title: string;
  type: 'item' | 'collapse' | 'group';
  translate?: string;
  icon?: string;
  hidden?: boolean;
  url?: string;
  classes?: string;
  groupClasses?: string;
  exactMatch?: boolean;
  external?: boolean;
  target?: boolean;
  breadcrumbs?: boolean;
  children?: NavigationItem[];
  link?: string;
  description?: string;
  path?: string;
  /** Permiso (o lista; basta uno) para mostrar la entrada. Un grupo sin entradas visibles se oculta. */
  permission?: string | string[];
}

// Menú de SIEG (components/app-sidebar.tsx y nav-admin.tsx). Los módulos de negocio se agregan al migrarlos.
export const NavigationItems: NavigationItem[] = [
  {
    id: 'principal',
    title: 'Principal',
    type: 'group',
    icon: 'icon-navigation',
    children: [
      {
        id: 'inicio',
        title: 'Inicio',
        type: 'item',
        classes: 'nav-item',
        url: '/inicio',
        icon: 'dashboard',
        breadcrumbs: false
      },
      {
        id: 'presupuesto',
        title: 'Presupuesto',
        type: 'item',
        classes: 'nav-item',
        url: '/presupuesto',
        icon: 'calculator',
        permission: 'presupuesto-index'
      },
      {
        id: 'gasto',
        title: 'Ejercicio y control del gasto',
        type: 'item',
        classes: 'nav-item',
        url: '/requisiciones/gasto',
        icon: 'fund',
        permission: 'gasto-index'
      },
      {
        id: 'requisiciones-menu',
        title: 'Requisiciones',
        type: 'collapse',
        icon: 'file-text',
        children: [
          {
            id: 'requisiciones',
            title: 'Requisiciones',
            type: 'item',
            url: '/requisiciones',
            exactMatch: true, // /requisiciones/gasto tiene su propia entrada.
            permission: 'requisiciones-index'
          },
          {
            id: 'calendario-captura',
            title: 'Calendario de captura',
            type: 'item',
            url: '/catalogos/calendario-captura',
            permission: 'catalogos-calendario-captura-index'
          },
          {
            id: 'modificaciones',
            title: 'Modificaciones presupuestales',
            type: 'item',
            url: '/modificaciones',
            permission: 'modificaciones-index'
          },
          {
            id: 'calendario-modificaciones',
            title: 'Calendario de modificaciones presupuestales',
            type: 'item',
            url: '/catalogos/calendario-modificaciones',
            permission: 'catalogos-calendario-modificaciones-index'
          }
        ]
      },
      {
        id: 'recursos-materiales-menu',
        title: 'Recursos Materiales',
        type: 'collapse',
        icon: 'inbox',
        children: [
          {
            id: 'recursos-materiales',
            title: 'Listado',
            type: 'item',
            url: '/recursos-materiales',
            permission: 'recursos-materiales-index'
          },
          {
            id: 'almacen',
            title: 'Almacén',
            type: 'item',
            url: '/almacen',
            exactMatch: true, // el inventario tiene su propia entrada.
            permission: 'almacen-index'
          },
          {
            id: 'ordenes-compra',
            title: 'Órdenes de compra',
            type: 'item',
            url: '/ordenes-compra',
            permission: ['recursos-materiales-index', 'ordenes-compra-create', 'ordenes-compra-cancelar', 'ordenes-compra-pagar', 'ordenes-compra-verificar-sat']
          },
          {
            id: 'inventario',
            title: 'Inventario',
            type: 'item',
            url: '/almacen/inventario',
            permission: 'almacen-index'
          }
        ]
      }
    ]
  },
  {
    id: 'egresos',
    title: 'Egresos',
    type: 'group',
    icon: 'icon-navigation',
    children: [
      {
        id: 'proveedores',
        title: 'Proveedores',
        type: 'item',
        classes: 'nav-item',
        url: '/catalogos/proveedores',
        icon: 'idcard',
        permission: 'proveedores-index'
      },
      {
        id: 'clc',
        title: 'Trámite de CLC',
        type: 'item',
        classes: 'nav-item',
        url: '/clc',
        icon: 'swap',
        permission: 'clc-index'
      },
      {
        id: 'facturas',
        title: 'Facturas',
        type: 'item',
        classes: 'nav-item',
        url: '/facturas',
        icon: 'file-done',
        permission: 'facturas-index'
      },
      {
        id: 'vehiculos-menu',
        title: 'Vehículos',
        type: 'collapse',
        icon: 'car',
        children: [
          {
            id: 'padron-vehicular',
            title: 'Padrón vehicular',
            type: 'item',
            url: '/catalogos/vehiculos',
            permission: 'catalogos-vehiculos-index'
          },
          {
            id: 'mantenimientos',
            title: 'Mantenimiento',
            type: 'item',
            url: '/vehiculos/mantenimientos',
            permission: 'vehiculos-mantenimiento'
          }
        ]
      }
    ]
  },
  {
    id: 'catalogos',
    title: 'Catálogos',
    type: 'group',
    icon: 'icon-navigation',
    children: [
      {
        id: 'catalogos-generales',
        title: 'Catálogos generales',
        type: 'item',
        classes: 'nav-item',
        url: '/catalogos',
        exactMatch: true, // Proveedores tiene su propia entrada.
        icon: 'database',
        // Proveedores usa el prefijo "proveedores" en lugar de "catalogos-proveedores".
        permission: ['catalogos-*-index', 'proveedores-index']
      }
    ]
  },
  {
    id: 'administracion',
    title: 'Administración',
    type: 'group',
    icon: 'icon-navigation',
    children: [
      {
        id: 'usuarios',
        title: 'Usuarios',
        type: 'item',
        classes: 'nav-item',
        url: '/usuarios',
        icon: 'team',
        permission: 'users-index'
      },
      {
        id: 'roles',
        title: 'Roles',
        type: 'item',
        classes: 'nav-item',
        url: '/roles',
        icon: 'safety',
        permission: 'roles-index'
      },
      {
        id: 'tickets',
        title: 'Tickets de soporte',
        type: 'item',
        classes: 'nav-item',
        url: '/tickets',
        icon: 'customer-service',
        permission: 'tickets-index'
      },
      {
        id: 'auditoria',
        title: 'Bitácora de auditoría',
        type: 'item',
        classes: 'nav-item',
        url: '/auditoria',
        icon: 'audit',
        permission: 'auditoria-index'
      },
      {
        id: 'permisos',
        title: 'Permisos',
        type: 'item',
        classes: 'nav-item',
        url: '/permisos',
        icon: 'key',
        permission: 'permisos-index'
      }
    ]
  }
];

/** Copia del menú solo con lo que el usuario puede ver. */
export function filtrarNavegacion(items: NavigationItem[], can: (permiso: string | string[]) => boolean): NavigationItem[] {
  return items
    .filter((item) => !item.permission || can(item.permission))
    .map((item) => (item.children ? { ...item, children: filtrarNavegacion(item.children, can) } : item))
    .filter((item) => item.type === 'item' || (item.children?.length ?? 0) > 0);
}
