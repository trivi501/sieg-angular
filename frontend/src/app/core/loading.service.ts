import { HttpContextToken, HttpInterceptorFn } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { finalize } from 'rxjs';

/** Peticiones que no deben mostrar el loader (p. ej. la consulta periódica de notificaciones). */
export const SIN_LOADER = new HttpContextToken<boolean>(() => false);

/** Cuenta navegaciones y peticiones en curso para mostrar el loader del template. */
@Injectable({ providedIn: 'root' })
export class LoadingService {
  private pendientes = signal(0);
  readonly ocupado = computed(() => this.pendientes() > 0);

  iniciar(): void {
    this.pendientes.update((n) => n + 1);
  }

  terminar(): void {
    this.pendientes.update((n) => Math.max(0, n - 1));
  }
}

export const loadingInterceptor: HttpInterceptorFn = (req, next) => {
  if (req.context.get(SIN_LOADER)) {
    return next(req);
  }
  const loading = inject(LoadingService);
  loading.iniciar();
  return next(req).pipe(finalize(() => loading.terminar()));
};
