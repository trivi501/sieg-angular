import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { RouterLink } from '@angular/router';
import { NgbActiveModal, NgbModal } from '@ng-bootstrap/ng-bootstrap';

import { environment } from 'src/environments/environment';
import { money } from 'src/app/core/formato';

interface Mantenimiento {
  id: number;
  folio: string;
  fecha_recepcion: string | null;
  fecha_entrega: string | null;
  tipo: string;
  kilometraje: number | null;
  descripcion: string | null;
  estado: string;
  requisicion_id: number | null;
  requisicion: string | null;
  requisicion_estado: string | null;
  orden: string | null;
  costo: number;
  situacion: 'pagado' | 'comprometido' | 'estimado' | 'cancelado' | 'sin_requisicion';
}

interface Historial {
  vehiculo: { id: number; texto: string; secretaria: string | null };
  mantenimientos: Mantenimiento[];
  totales: { mantenimientos: number; pagado: number; comprometido: number; estimado: number; total: number };
}

const SITUACIONES: Record<Mantenimiento['situacion'], { texto: string; clase: string }> = {
  pagado: { texto: 'Pagado', clase: 'bg-light-success text-success' },
  comprometido: { texto: 'Comprometido (orden de compra)', clase: 'bg-light-primary text-primary' },
  estimado: { texto: 'Estimado (requisición)', clase: 'bg-light-warning text-warning' },
  cancelado: { texto: 'Cancelado', clase: 'bg-light-secondary text-secondary' },
  sin_requisicion: { texto: 'Sin requisición', clase: 'bg-light-secondary text-secondary' }
};

/** Ventana con todos los mantenimientos de un vehículo del padrón y cuánto han costado. */
@Component({
  selector: 'app-historial-vehiculo',
  imports: [DatePipe, RouterLink],
  template: `
    <div class="modal-header">
      <h5 class="modal-title">
        Mantenimientos y costo
        @if (datos(); as d) {
          <small class="d-block text-muted fw-normal">{{ d.vehiculo.texto }} · {{ d.vehiculo.secretaria ?? 'sin secretaría' }}</small>
        }
      </h5>
      <button type="button" class="btn-close" (click)="activo.dismiss()"></button>
    </div>
    <div class="modal-body">
      @if (error()) {
        <div class="alert alert-danger py-2 mb-0">{{ error() }}</div>
      } @else if (datos(); as d) {
        <div class="row g-2 mb-3">
          @for (t of [
            { etiqueta: 'Mantenimientos', valor: '' + d.totales.mantenimientos },
            { etiqueta: 'Costo total', valor: money(d.totales.total) },
            { etiqueta: 'Pagado', valor: money(d.totales.pagado) },
            { etiqueta: 'Comprometido', valor: money(d.totales.comprometido) },
            { etiqueta: 'Estimado', valor: money(d.totales.estimado) }
          ]; track t.etiqueta) {
            <div class="col-6 col-md">
              <div class="border rounded px-3 py-2 h-100">
                <small class="text-muted d-block">{{ t.etiqueta }}</small>
                <span class="fw-semibold">{{ t.valor }}</span>
              </div>
            </div>
          }
        </div>
        <p class="small text-muted">
          Costo de cada mantenimiento: el importe de su orden de compra (pagado o comprometido); si aún no hay orden, el total de la requisición
          (estimado). Los cancelados o sin requisición no suman.
        </p>

        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Recepción</th>
                <th>Entrega</th>
                <th>Tipo</th>
                <th class="text-end">Km</th>
                <th>Falla o servicio</th>
                <th>Requisición</th>
                <th class="text-end">Costo</th>
              </tr>
            </thead>
            <tbody>
              @for (m of d.mantenimientos; track m.id) {
                <tr>
                  <td class="text-nowrap fw-semibold">
                    {{ m.folio }}
                    <small class="d-block text-muted fw-normal">{{ m.estado }}</small>
                  </td>
                  <td class="text-nowrap">{{ m.fecha_recepcion | date: 'dd/MM/yyyy' }}</td>
                  <td class="text-nowrap">{{ (m.fecha_entrega | date: 'dd/MM/yyyy') ?? '—' }}</td>
                  <td>{{ m.tipo }}</td>
                  <td class="text-end">{{ m.kilometraje ?? '—' }}</td>
                  <td style="max-width: 240px">{{ m.descripcion }}</td>
                  <td class="text-nowrap">
                    @if (m.requisicion_id) {
                      <a [routerLink]="['/requisiciones', m.requisicion_id]" (click)="activo.dismiss()">{{ m.requisicion }}</a>
                      <small class="d-block text-muted">{{ m.requisicion_estado }}{{ m.orden ? ' · ' + m.orden : '' }}</small>
                    } @else {
                      —
                    }
                  </td>
                  <td class="text-end text-nowrap">
                    <span class="font-monospace">{{ money(m.costo) }}</span>
                    <span class="badge d-block mt-1 {{ situacion(m).clase }}">{{ situacion(m).texto }}</span>
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">Este vehículo no ha tenido mantenimientos.</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      } @else {
        <p class="text-muted mb-0">Cargando…</p>
      }
    </div>
  `
})
export class HistorialVehiculoComponent {
  private http = inject(HttpClient);
  readonly activo = inject(NgbActiveModal);
  readonly money = money;

  datos = signal<Historial | null>(null);
  error = signal('');

  /** Abre la ventana del vehículo indicado. */
  static abrir(modal: NgbModal, vehiculoId: number): void {
    const ref = modal.open(HistorialVehiculoComponent, { size: 'xl', scrollable: true });
    (ref.componentInstance as HistorialVehiculoComponent).cargar(vehiculoId);
  }

  cargar(vehiculoId: number): void {
    this.http.get<Historial>(`${environment.apiUrl}/vehiculos/${vehiculoId}/mantenimientos`).subscribe({
      next: (d) => this.datos.set(d),
      error: () => this.error.set('No se pudo cargar el historial de mantenimientos.')
    });
  }

  situacion(m: Mantenimiento): { texto: string; clase: string } {
    return SITUACIONES[m.situacion];
  }
}
