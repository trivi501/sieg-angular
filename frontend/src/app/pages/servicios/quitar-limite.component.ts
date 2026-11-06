import { Component, DestroyRef, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';

interface EstadoLimite {
  limite: number;
  minutos: number;
  /** Mientras está quitado: hasta cuándo y quién lo quitó. */
  sin_limite_hasta: string | null;
  sin_limite_por: string | null;
  historial: { id: number; desde: string | null; hasta: string; por: string | null; restablecido_at: string | null; restablecido_por: string | null }[];
}

/**
 * Menú "Quitar límite" (permiso fondos-sin-limite): quita a todos los fondos revolventes el límite de $2,000 por
 * factura durante 5 minutos, con cuenta regresiva; al terminar vuelve solo. Se puede restablecer antes.
 */
@Component({
  selector: 'app-quitar-limite',
  imports: [DatePipe, AccionComponent],
  template: `
    <div class="mb-3">
      <h4 class="mb-1">Quitar límite</h4>
      <p class="text-muted small mb-0">
        Quita a <b>todos los fondos revolventes</b> el límite de {{ money(e()?.limite ?? 2000) }} por factura durante {{ e()?.minutos ?? 5 }} minutos. Al terminar el
        tiempo vuelve solo; las facturas que se subieron mientras tanto se quedan.
      </p>
    </div>

    @if (aviso(); as a) {
      <div class="alert alert-{{ a.tipo }} alert-dismissible py-2">
        {{ a.texto }}
        <button type="button" class="btn-close" (click)="aviso.set(null)"></button>
      </div>
    }

    @if (e(); as e) {
      <div class="card" [class.border-warning]="!!e.sin_limite_hasta" [class.border-success]="!e.sin_limite_hasta">
        <div class="card-body text-center py-4">
          @if (e.sin_limite_hasta) {
            <span class="badge bg-light-warning text-warning fs-6 mb-2">Sin límite en todos los fondos</span>
            <div class="display-5 font-monospace fw-bold my-2">{{ cuenta(restante()) }}</div>
            <p class="text-muted small mb-3">
              Lo quitó {{ e.sin_limite_por ?? '—' }}. Vuelve a {{ money(e.limite) }} por factura a las {{ e.sin_limite_hasta | date: 'HH:mm:ss' }}.
            </p>
            <div class="d-flex justify-content-center gap-2">
              <button type="button" class="btn btn-outline-dark" [disabled]="ocupado()" (click)="restablecer()">Restablecer límite ahora</button>
              <button type="button" class="btn btn-warning" [disabled]="ocupado()" (click)="quitar()">Reiniciar {{ e.minutos }} minutos</button>
            </div>
          } @else {
            <span class="badge bg-light-success text-success fs-6 mb-2">Límite vigente</span>
            <div class="display-5 fw-bold my-2">{{ money(e.limite) }}</div>
            <p class="text-muted small mb-3">por factura en los fondos revolventes</p>
            <button
              type="button"
              class="btn btn-lg btn-warning"
              [disabled]="ocupado()"
              (click)="quitar()"
              [appAccion]="'Quitar límite (' + e.minutos + ' min)'"
              [conNombre]="true"
              icono="safety-certificate"
            ></button>
          }
        </div>
      </div>

      @if (e.historial.length) {
        <div class="card">
          <div class="card-body">
            <h6 class="mb-2">Últimas veces que se quitó</h6>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Desde</th>
                    <th>Hasta</th>
                    <th>Lo quitó</th>
                    <th>Restablecido antes</th>
                  </tr>
                </thead>
                <tbody>
                  @for (h of e.historial; track h.id) {
                    <tr>
                      <td>{{ h.desde | date: 'dd/MM/yyyy HH:mm:ss' }}</td>
                      <td>{{ h.hasta | date: 'HH:mm:ss' }}</td>
                      <td>{{ h.por ?? '—' }}</td>
                      <td>{{ h.restablecido_at ? (h.restablecido_at | date: 'HH:mm:ss') + ' · ' + (h.restablecido_por ?? '') : '—' }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          </div>
        </div>
      }
    } @else {
      <p class="text-muted">Cargando…</p>
    }
  `
})
export class QuitarLimiteComponent {
  private http = inject(HttpClient);
  private readonly api = `${environment.apiUrl}/fondos-limite`;
  readonly money = money;

  e = signal<EstadoLimite | null>(null);
  restante = signal(0);
  ocupado = signal(false);
  aviso = signal<{ tipo: 'success' | 'warning' | 'danger'; texto: string } | null>(null);

  constructor() {
    this.cargar();
    const reloj = setInterval(() => {
      const hasta = this.e()?.sin_limite_hasta;
      if (!hasta) return;
      const restante = new Date(hasta).getTime() - Date.now();
      this.restante.set(Math.max(0, restante));
      if (restante <= 0) {
        this.aviso.set({ tipo: 'success', texto: 'Terminó el tiempo: el límite de $2,000.00 por factura volvió en todos los fondos.' });
        this.cargar();
      }
    }, 1000);
    inject(DestroyRef).onDestroy(() => clearInterval(reloj));
  }

  cargar(): void {
    this.http.get<EstadoLimite>(this.api).subscribe((e) => {
      this.e.set(e);
      this.restante.set(e.sin_limite_hasta ? Math.max(0, new Date(e.sin_limite_hasta).getTime() - Date.now()) : 0);
    });
  }

  quitar(): void {
    this.accion('quitar', 'warning');
  }

  restablecer(): void {
    this.accion('restablecer', 'success');
  }

  private accion(que: 'quitar' | 'restablecer', tipo: 'success' | 'warning'): void {
    this.ocupado.set(true);
    this.http.post<{ message: string }>(`${this.api}/${que}`, {}).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.aviso.set({ tipo, texto: r.message });
        this.cargar();
      },
      error: (err: HttpErrorResponse) => {
        this.ocupado.set(false);
        this.aviso.set({ tipo: 'danger', texto: erroresDe(err).mensaje });
      }
    });
  }

  /** mm:ss */
  cuenta(ms: number): string {
    const s = Math.max(0, Math.ceil(ms / 1000));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  }
}
