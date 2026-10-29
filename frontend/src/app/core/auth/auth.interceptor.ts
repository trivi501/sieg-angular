import { HttpContextToken, HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';

import { environment } from 'src/environments/environment';
import { AuthService } from './auth.service';

/** Consultas en segundo plano (notificaciones): no cuentan como actividad del usuario para el cierre por inactividad. */
export const SEGUNDO_PLANO = new HttpContextToken<boolean>(() => false);

/** Agrega el token a las llamadas a la API y, si responde 401, cierra la sesión local. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);

  if (!req.url.startsWith(environment.apiUrl)) {
    return next(req);
  }

  const token = auth.token;
  const peticion = req.clone({
    setHeaders: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(req.context.get(SEGUNDO_PLANO) ? { 'X-Segundo-Plano': '1' } : {})
    }
  });

  return next(peticion).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401 && !req.url.endsWith('/login')) {
        // Con sesión abierta, el token venció (inactividad u otro cierre): se avisa en el login.
        auth.salir(auth.isAuthenticated() ? 'vencida' : undefined);
      }
      return throwError(() => error);
    })
  );
};
