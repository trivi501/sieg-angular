import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, catchError, firstValueFrom, map, of, tap } from 'rxjs';

import { environment } from 'src/environments/environment';

export interface Usuario {
  id: number;
  name: string;
  email: string;
  cargo: string | null;
  unidad: string | null;
  roles: string[];
  permissions: string[];
  /** Minutos sin actividad tras los que se cierra la sesión. */
  inactividad_minutos?: number;
}

/** Por qué se cerró la sesión sin que el usuario lo pidiera (se explica en el login). */
export type MotivoSalida = 'inactividad' | 'vencida';

const TOKEN_KEY = 'sieg_token';

/**
 * Sesión del usuario: token de Sanctum en localStorage y perfil (roles y permisos) en un signal.
 * Equivale a los props compartidos `auth` / `userPermissions` de Inertia en SIEG.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private http = inject(HttpClient);
  private router = inject(Router);

  readonly user = signal<Usuario | null>(null);
  readonly isAuthenticated = computed(() => this.user() !== null);
  private readonly permisos = computed(() => new Set(this.user()?.permissions ?? []));

  get token(): string | null {
    try {
      return localStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  }

  login(email: string, password: string): Observable<Usuario> {
    return this.http.post<{ token: string; user: Usuario }>(`${environment.apiUrl}/login`, { email, password }).pipe(
      tap(({ token, user }) => {
        localStorage.setItem(TOKEN_KEY, token);
        this.user.set(user);
      }),
      map(({ user }) => user)
    );
  }

  /** Al arrancar la app: si hay token, recupera el perfil; si ya no es válido, lo descarta. */
  restaurar(): Promise<void> {
    if (!this.token) {
      return Promise.resolve();
    }

    return firstValueFrom(
      this.http.get<Usuario>(`${environment.apiUrl}/me`).pipe(
        tap((user) => this.user.set(user)),
        map(() => undefined),
        catchError(() => {
          this.limpiar();
          return of(undefined);
        })
      )
    );
  }

  logout(): void {
    this.http
      .post(`${environment.apiUrl}/logout`, {})
      .pipe(catchError(() => of(null)))
      .subscribe(() => this.salir());
  }

  /** Sesión vencida o cerrada: limpia y manda al login (con el motivo, si no la cerró el usuario). */
  salir(motivo?: MotivoSalida): void {
    const volver = motivo ? this.router.url : null;
    this.limpiar();
    this.router.navigate(['/login'], { queryParams: motivo ? { motivo, ...(volver && volver !== '/login' ? { volver } : {}) } : {} });
  }

  /** Inactividad: se borra el token en el servidor (si aún sirve) y se va al login con el aviso. */
  cerrarPorInactividad(): void {
    if (!this.user()) return;
    this.http
      .post(`${environment.apiUrl}/logout`, {})
      .pipe(catchError(() => of(null)))
      .subscribe();
    this.salir('inactividad');
  }

  /** Acepta un permiso o varios (basta con tener uno). `*` es comodín: `catalogos-*-index`. */
  can(permiso: string | string[]): boolean {
    const lista = Array.isArray(permiso) ? permiso : [permiso];
    return lista.some((p) => {
      if (!p.includes('*')) {
        return this.permisos().has(p);
      }
      const patron = new RegExp('^' + p.split('*').map((s) => s.replace(/[.+?^${}()|[\]\\-]/g, '\\$&')).join('.*') + '$');
      return [...this.permisos()].some((q) => patron.test(q));
    });
  }

  hasRole(...roles: string[]): boolean {
    return roles.some((r) => this.user()?.roles.includes(r));
  }

  private limpiar(): void {
    try {
      localStorage.removeItem(TOKEN_KEY);
    } catch {
      // sin almacenamiento disponible: no hay nada que borrar
    }
    this.user.set(null);
  }
}
