import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { AuthService } from './auth.service';

/** Rutas del panel: requiere sesión. */
export const authGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  return auth.isAuthenticated() ? true : inject(Router).createUrlTree(['/login'], { queryParams: { volver: state.url } });
};

/** Login: si ya hay sesión, va al inicio. */
export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.isAuthenticated() ? inject(Router).createUrlTree(['/inicio']) : true;
};

/**
 * Exige el permiso de `data.permission` (uno o varios; basta uno), como el middleware
 * `permission:` de las rutas de SIEG. Sin él, manda a /sin-acceso.
 */
export const permissionGuard: CanActivateFn = (route) => {
  const auth = inject(AuthService);
  const permiso = route.data['permission'] as string | string[] | undefined;
  return !permiso || auth.can(permiso) ? true : inject(Router).createUrlTree(['/sin-acceso']);
};
