import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { Agrupacion, CombustibleService, ReporteCombustible, litros } from './combustible.service';

/** Consumo y gasto de combustible por secretaría, vehículo, mes o producto en un periodo (por la fecha del ticket). */
@Component({
  selector: 'app-combustible-reporte',
  imports: [FormsModule, RouterLink, AccionComponent],
  template: `
    <div class="mb-3">
      <a routerLink="/combustible" class="small text-muted">← Bitácora de combustible</a>
      <h4 class="mb-1">Reporte de consumo de combustible</h4>
      <p class="text-muted small mb-0">Tickets de las bitácoras no canceladas, por la fecha del ticket.</p>
    </div>

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3 align-items-end" (ngSubmit)="cargar()">
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="desde">Desde</label>
            <input id="desde" name="desde" type="date" class="form-control form-control-sm" [(ngModel)]="filtros.desde" />
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="hasta">Hasta</label>
            <input id="hasta" name="hasta" type="date" class="form-control form-control-sm" [(ngModel)]="filtros.hasta" />
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="agrupar">Por</label>
            <select id="agrupar" name="agrupar" class="form-select form-select-sm" [(ngModel)]="filtros.agrupar" (ngModelChange)="cargar()">
              @for (a of agrupaciones(); track a[0]) {
                <option [value]="a[0]">{{ a[1] }}</option>
              }
            </select>
          </div>
          @if (r()?.unidades?.length) {
            <div class="col-6 col-md-4">
              <label class="form-label small mb-0" for="unidad">Secretaría</label>
              <select id="unidad" name="unidad" class="form-select form-select-sm" [(ngModel)]="filtros.unidad" (ngModelChange)="cargar()">
                <option [ngValue]="null">Todas</option>
                @for (u of r()!.unidades; track u.id) {
                  <option [ngValue]="u.id">{{ u.clave }} — {{ u.nombre }}</option>
                }
              </select>
            </div>
          }
          <div class="col-auto d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" appAccion="Consultar" icono="search"></button>
            <button
              type="button"
              class="btn btn-sm btn-outline-success"
              (click)="excel()"
              [disabled]="!r()"
              appAccion="Exportar a Excel"
              icono="file-excel"
            ></button>
          </div>
        </form>

        @if (error()) {
          <div class="alert alert-danger py-2">{{ error() }}</div>
        }

        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>{{ r()?.agrupaciones?.[filtros.agrupar] ?? 'Grupo' }}</th>
                @if (filtros.agrupar === 'vehiculo') {
                  <th>Secretaría</th>
                }
                <th class="text-end">Tickets</th>
                <th class="text-end">Litros</th>
                <th class="text-end">Importe</th>
                <th class="text-end">Precio promedio</th>
                <th style="width: 18%"></th>
              </tr>
            </thead>
            <tbody>
              @for (f of r()?.filas ?? []; track $index) {
                <tr>
                  <td class="fw-semibold">{{ f.grupo }}</td>
                  @if (filtros.agrupar === 'vehiculo') {
                    <td class="small text-muted">{{ f.unidad }}</td>
                  }
                  <td class="text-end">{{ f.tickets }}</td>
                  <td class="text-end font-monospace">{{ litros(f.litros) }}</td>
                  <td class="text-end font-monospace">{{ money(f.importe) }}</td>
                  <td class="text-end font-monospace">{{ f.precio_promedio !== null ? money(f.precio_promedio) : '—' }}</td>
                  <td>
                    <div class="progress" style="height: 6px" [title]="porcentaje(f.importe) + '% del importe'">
                      <div class="progress-bar" [style.width.%]="porcentaje(f.importe)"></div>
                    </div>
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td [attr.colspan]="filtros.agrupar === 'vehiculo' ? 7 : 6" class="text-center text-muted py-4">
                    {{ r() ? 'Sin tickets en el periodo.' : 'Cargando…' }}
                  </td>
                </tr>
              }
            </tbody>
            @if (r()?.filas?.length) {
              <tfoot>
                <tr class="fw-bold">
                  <td [attr.colspan]="filtros.agrupar === 'vehiculo' ? 2 : 1">Total</td>
                  <td class="text-end">{{ r()!.totales.tickets }}</td>
                  <td class="text-end font-monospace">{{ litros(r()!.totales.litros) }}</td>
                  <td class="text-end font-monospace">{{ money(r()!.totales.importe) }}</td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            }
          </table>
        </div>
      </div>
    </div>
  `,
  styles: `
    td,
    th {
      font-size: 13px;
    }
  `
})
export class CombustibleReporteComponent {
  private servicio = inject(CombustibleService);

  readonly money = money;
  readonly litros = litros;

  r = signal<ReporteCombustible | null>(null);
  error = signal('');
  agrupaciones = signal<[Agrupacion, string][]>([
    ['secretaria', 'Secretaría'],
    ['vehiculo', 'Vehículo'],
    ['mes', 'Mes'],
    ['producto', 'Producto']
  ]);
  filtros: { desde: string; hasta: string; agrupar: Agrupacion; unidad: number | null } = {
    desde: '',
    hasta: '',
    agrupar: 'secretaria',
    unidad: null
  };

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.error.set('');
    this.servicio.reporte(this.filtros).subscribe({
      next: (r) => {
        this.r.set(r);
        this.filtros.desde = r.filtros.desde;
        this.filtros.hasta = r.filtros.hasta;
      },
      error: (e) => this.error.set(e.error?.message ?? 'No se pudo consultar.')
    });
  }

  porcentaje(importe: number): number {
    const total = this.r()?.totales.importe ?? 0;
    return total > 0 ? Math.round((importe / total) * 1000) / 10 : 0;
  }

  excel(): void {
    this.servicio.reporteExcel(this.filtros).subscribe((b) => abrirBlob(b, `consumo_combustible_por_${this.filtros.agrupar}.xlsx`, true));
  }
}
