import { Component, DestroyRef, NgZone, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { AuthService } from './auth.service';

/** Última actividad del usuario, compartida entre pestañas (si trabaja en una, las otras no se cierran). */
const ACTIVIDAD_KEY = 'sieg_actividad';
/** Cuánto antes del cierre se avisa. */
const AVISO_MS = 2 * 60_000;
/** Cada cuánto se revisa. */
const REVISION_MS = 5_000;
/** Si el usuario trabaja sin llamar a la API (p. ej. llenando un formulario largo), se le avisa al servidor. */
const LATIDO_MS = 4 * 60_000;
const EVENTOS = ['mousedown', 'keydown', 'wheel', 'touchstart', 'scroll'];

/**
 * Cierre de sesión por inactividad (los minutos los da el backend: inactividad_minutos). Dos minutos antes avisa
 * con una cuenta regresiva; al vencer cierra la sesión. El backend también vence el token por su cuenta.
 */
@Component({
  selector: 'app-inactividad',
  template: `
    @if (restante() !== null) {
      <div class="modal-backdrop fade show"></div>
      <div class="modal fade show d-block" role="dialog" aria-modal="true" aria-labelledby="tituloInactividad">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="tituloInactividad">¿Sigues ahí?</h5>
            </div>
            <div class="modal-body">
              Por seguridad, tu sesión se cerrará por inactividad en <b class="font-monospace">{{ texto(restante()!) }}</b>.
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" (click)="auth.logout()">Cerrar sesión</button>
              <button type="button" class="btn btn-primary" (click)="seguir()">Seguir trabajando</button>
            </div>
          </div>
        </div>
      </div>
    }
  `
})
export class InactividadComponent {
  protected auth = inject(AuthService);
  private http = inject(HttpClient);

  /** Milisegundos para el cierre mientras se muestra el aviso; null sin aviso. */
  restante = signal<number | null>(null);
  private ultimoLatido = Date.now();
  private actividadLocal = Date.now();

  constructor() {
    this.registrar();
    const marcar = () => {
      // Con el aviso abierto, solo el botón cuenta (que no se renueve sin querer al mover el ratón).
      if (this.restante() === null) this.registrar();
    };
    const zona = inject(NgZone);
    zona.runOutsideAngular(() => EVENTOS.forEach((e) => window.addEventListener(e, marcar, { passive: true, capture: true })));
    const temporizador = setInterval(() => this.revisar(), REVISION_MS);
    inject(DestroyRef).onDestroy(() => {
      clearInterval(temporizador);
      EVENTOS.forEach((e) => window.removeEventListener(e, marcar, { capture: true }));
    });
  }

  seguir(): void {
    this.registrar();
    this.restante.set(null);
    this.latido();
  }

  texto(ms: number): string {
    const s = Math.max(0, Math.ceil(ms / 1000));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  }

  private get limiteMs(): number {
    return (this.auth.user()?.inactividad_minutos ?? 30) * 60_000;
  }

  private registrar(): void {
    this.actividadLocal = Date.now();
    try {
      localStorage.setItem(ACTIVIDAD_KEY, String(this.actividadLocal));
    } catch {
      // sin almacenamiento: cuenta solo esta pestaña
    }
  }

  /** La actividad más reciente entre esta pestaña y las demás. */
  private ultima(): number {
    let compartida = 0;
    try {
      compartida = Number(localStorage.getItem(ACTIVIDAD_KEY)) || 0;
    } catch {
      compartida = 0;
    }
    return Math.max(this.actividadLocal, compartida);
  }

  private revisar(): void {
    if (!this.auth.isAuthenticated()) return;
    const ultima = this.ultima();
    const restante = this.limiteMs - (Date.now() - ultima);
    if (restante <= 0) {
      this.restante.set(null);
      this.auth.cerrarPorInactividad();
      return;
    }
    this.restante.set(restante <= AVISO_MS ? restante : null);
    // Trabajando sin llamadas a la API: un latido para que el servidor no la cierre.
    if (restante > AVISO_MS && ultima > this.ultimoLatido && Date.now() - this.ultimoLatido >= LATIDO_MS) {
      this.latido();
    }
  }

  private latido(): void {
    this.ultimoLatido = Date.now();
    this.http.get(`${environment.apiUrl}/me`).subscribe({ error: () => undefined });
  }
}
