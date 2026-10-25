import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { FormsModule } from '@angular/forms';

import { environment } from 'src/environments/environment';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { abrirBlob } from '../requisiciones/requisiciones.service';

interface FacturaIsr {
  rfc: string;
  emisor: string | null;
  uuid: string | null;
  serie_folio: string | null;
  fecha: string | null;
  base: number;
  iva: number;
  isr: number;
  total: number;
  clc: string;
  orden: string;
  clc_fecha: string | null;
  pago_fecha: string | null;
  pago: string | null;
}

interface ReporteIsr {
  facturas: FacturaIsr[];
  por_rfc: { rfc: string; emisor: string | null; facturas: number; base: number; isr: number }[];
  totales: { facturas: number; base: number; isr: number; total: number };
  filtros: { mes: string; base: 'pago' | 'clc'; nombre_mes: string; descripcion: string };
}

/**
 * Reporte mensual del ISR retenido (Departamento de Contabilidad): las facturas con ISR retenido de las CLC pagadas
 * en el mes (o emitidas, a elegir), con resumen por RFC del emisor y Excel.
 */
@Component({
  selector: 'app-isr',
  imports: [FormsModule, DatePipe, AccionComponent],
  template: `
    <div class="mb-3">
      <h4 class="mb-1">ISR retenido</h4>
      <p class="text-muted small mb-0">
        Facturas con ISR retenido de las CLC pagadas en el mes (las retenciones se enteran en el mes en que se pagan) o, a elegir, de las
        CLC emitidas en el mes. No cuenta las CLC canceladas.
      </p>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="row g-2 mb-3 align-items-end">
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="mes">Mes</label>
            <input id="mes" type="month" class="form-control form-control-sm" [(ngModel)]="mes" (ngModelChange)="cargar()" />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-0" for="base">Por fecha de</label>
            <select id="base" class="form-select form-select-sm sin-busqueda" [(ngModel)]="base" (ngModelChange)="cargar()">
              <option value="pago">Pago (CLC pagadas en el mes)</option>
              <option value="clc">CLC (emitidas en el mes)</option>
            </select>
          </div>
          <div class="col-auto">
            <button
              type="button"
              class="btn btn-sm btn-outline-success"
              [disabled]="!r()"
              (click)="excel()"
              appAccion="Exportar a Excel"
              icono="file-excel"
            ></button>
          </div>
        </div>

        @if (r(); as r) {
          <div class="row g-3 small mb-3">
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Facturas</span>
              <b class="fs-6">{{ r.totales.facturas }}</b>
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Base</span>
              <b class="fs-6 font-monospace">{{ money(r.totales.base) }}</b>
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">ISR retenido</span>
              <b class="fs-6 font-monospace">{{ money(r.totales.isr) }}</b>
            </div>
          </div>

          @if (r.por_rfc.length) {
            <h6>Por RFC</h6>
            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>RFC</th>
                    <th>Emisor</th>
                    <th class="text-center">Facturas</th>
                    <th class="text-end">Base</th>
                    <th class="text-end">ISR retenido</th>
                  </tr>
                </thead>
                <tbody>
                  @for (p of r.por_rfc; track p.rfc) {
                    <tr>
                      <td class="fw-semibold">{{ p.rfc }}</td>
                      <td>{{ p.emisor }}</td>
                      <td class="text-center">{{ p.facturas }}</td>
                      <td class="text-end font-monospace">{{ money(p.base) }}</td>
                      <td class="text-end font-monospace">{{ money(p.isr) }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }

          <h6>Facturas</h6>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>RFC / emisor</th>
                  <th>Factura</th>
                  <th class="text-end">Base</th>
                  <th class="text-end">IVA</th>
                  <th class="text-end">ISR</th>
                  <th class="text-end">Total</th>
                  <th>CLC</th>
                  <th>Pago</th>
                </tr>
              </thead>
              <tbody>
                @for (f of r.facturas; track $index) {
                  <tr>
                    <td class="small">
                      <b>{{ f.rfc }}</b>
                      <span class="d-block text-muted">{{ f.emisor }}</span>
                    </td>
                    <td class="small">
                      {{ f.serie_folio ?? '—' }} · {{ f.fecha | date: 'dd/MM/yyyy' }}
                      <span class="d-block text-muted" style="font-size: 10.5px">{{ f.uuid }}</span>
                    </td>
                    <td class="text-end font-monospace">{{ money(f.base) }}</td>
                    <td class="text-end font-monospace">{{ money(f.iva) }}</td>
                    <td class="text-end font-monospace fw-semibold">{{ money(f.isr) }}</td>
                    <td class="text-end font-monospace">{{ money(f.total) }}</td>
                    <td class="small">
                      {{ f.clc }}
                      <span class="d-block text-muted">{{ f.orden }}</span>
                    </td>
                    <td class="small">
                      {{ f.pago_fecha | date: 'dd/MM/yyyy' }}
                      <span class="d-block text-muted">{{ f.pago }}</span>
                    </td>
                  </tr>
                } @empty {
                  <tr>
                    <td colspan="8" class="text-center text-muted py-4">Sin ISR retenido en {{ r.filtros.nombre_mes }}.</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        } @else {
          <p class="text-muted">Cargando…</p>
        }
      </div>
    </div>
  `,
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
  `
})
export class IsrComponent {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/contabilidad/isr`;

  readonly money = money;

  r = signal<ReporteIsr | null>(null);
  mes = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' }).slice(0, 7);
  base: 'pago' | 'clc' = 'pago';

  constructor() {
    this.cargar();
  }

  private params(): HttpParams {
    return new HttpParams().set('mes', this.mes).set('base', this.base);
  }

  cargar(): void {
    if (!this.mes) return;
    this.http.get<ReporteIsr>(this.api, { params: this.params() }).subscribe((r) => this.r.set(r));
  }

  excel(): void {
    this.http
      .get(`${this.api}/excel`, { params: this.params(), responseType: 'blob' })
      .subscribe((b) => abrirBlob(b, `isr_retenido_${this.mes}.xlsx`, true));
  }
}
