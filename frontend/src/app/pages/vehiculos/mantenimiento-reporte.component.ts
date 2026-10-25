import { Component, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { NgbModal } from '@ng-bootstrap/ng-bootstrap';

import { environment } from 'src/environments/environment';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { HistorialVehiculoComponent } from './historial-vehiculo.component';

type Agrupacion = 'secretaria' | 'vehiculo';

interface FilaCosto {
  grupo: string;
  vehiculo_id: number | null;
  secretaria: string;
  servicios: number;
  preventivos: number;
  correctivos: number;
  vehiculos: number;
  pagado: number;
  comprometido: number;
  estimado: number;
  total: number;
}

interface ReporteMantenimiento {
  filas: FilaCosto[];
  totales: Record<'servicios' | 'preventivos' | 'correctivos' | 'pagado' | 'comprometido' | 'estimado' | 'total', number>;
  agrupaciones: Record<Agrupacion, string>;
  filtros: { desde: string; hasta: string; agrupar: Agrupacion; unidad: number | null; descripcion: string };
  unidades: { id: number; clave: string; nombre: string }[];
}

/**
 * Costo del mantenimiento vehicular por secretaría de resguardo o por vehículo en un periodo (por la fecha de
 * recepción en el taller): pagado, comprometido (con orden de compra) y estimado (solo requisición).
 */
@Component({
  selector: 'app-mantenimiento-reporte',
  imports: [FormsModule, RouterLink, AccionComponent, SelectBusquedaDirective],
  template: `
    <div class="mb-3">
      <a routerLink="/vehiculos/mantenimientos" class="small text-muted">← Mantenimiento vehicular</a>
      <h4 class="mb-1">Costo de mantenimiento vehicular</h4>
      <p class="text-muted small mb-0">
        Por la fecha en que se recibió el vehículo en el taller. Pagado y comprometido salen de la orden de compra; estimado, de la
        requisición que todavía no tiene orden. Los cancelados cuentan como servicio pero no suman costo.
      </p>
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
            <select
              id="agrupar"
              name="agrupar"
              class="form-select form-select-sm sin-busqueda"
              [(ngModel)]="filtros.agrupar"
              (ngModelChange)="cargar()"
            >
              <option value="secretaria">Secretaría</option>
              <option value="vehiculo">Vehículo</option>
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
                <th>{{ porVehiculo() ? 'Vehículo' : 'Secretaría' }}</th>
                @if (porVehiculo()) {
                  <th>Secretaría</th>
                }
                <th class="text-end">Servicios</th>
                <th class="text-end">Prev. / corr.</th>
                @if (!porVehiculo()) {
                  <th class="text-end">Vehículos</th>
                }
                <th class="text-end">Pagado</th>
                <th class="text-end">Comprometido</th>
                <th class="text-end">Estimado</th>
                <th class="text-end">Total</th>
                <th style="width: 12%"></th>
              </tr>
            </thead>
            <tbody>
              @for (f of r()?.filas ?? []; track $index) {
                <tr>
                  <td class="fw-semibold">
                    @if (f.vehiculo_id) {
                      <a href="" (click)="$event.preventDefault(); historial(f.vehiculo_id)" title="Historial y costo de cada servicio">
                        {{ f.grupo }}
                      </a>
                    } @else {
                      {{ f.grupo }}
                    }
                  </td>
                  @if (porVehiculo()) {
                    <td class="small text-muted">{{ f.secretaria }}</td>
                  }
                  <td class="text-end">{{ f.servicios }}</td>
                  <td class="text-end">{{ f.preventivos }} / {{ f.correctivos }}</td>
                  @if (!porVehiculo()) {
                    <td class="text-end">{{ f.vehiculos }}</td>
                  }
                  <td class="text-end font-monospace">{{ money(f.pagado) }}</td>
                  <td class="text-end font-monospace">{{ money(f.comprometido) }}</td>
                  <td class="text-end font-monospace">{{ money(f.estimado) }}</td>
                  <td class="text-end font-monospace fw-semibold">{{ money(f.total) }}</td>
                  <td>
                    <div class="progress" style="height: 6px" [title]="porcentaje(f.total) + '% del costo'">
                      <div class="progress-bar" [style.width.%]="porcentaje(f.total)"></div>
                    </div>
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="9" class="text-center text-muted py-4">{{ r() ? 'Sin servicios en el periodo.' : 'Cargando…' }}</td>
                </tr>
              }
            </tbody>
            @if (r()?.filas?.length) {
              <tfoot>
                <tr class="fw-bold">
                  <td [attr.colspan]="porVehiculo() ? 2 : 1">Total</td>
                  <td class="text-end">{{ r()!.totales.servicios }}</td>
                  <td class="text-end">{{ r()!.totales.preventivos }} / {{ r()!.totales.correctivos }}</td>
                  @if (!porVehiculo()) {
                    <td></td>
                  }
                  <td class="text-end font-monospace">{{ money(r()!.totales.pagado) }}</td>
                  <td class="text-end font-monospace">{{ money(r()!.totales.comprometido) }}</td>
                  <td class="text-end font-monospace">{{ money(r()!.totales.estimado) }}</td>
                  <td class="text-end font-monospace">{{ money(r()!.totales.total) }}</td>
                  <td></td>
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
export class MantenimientoReporteComponent {
  private http = inject(HttpClient);
  private modal = inject(NgbModal);
  private api = `${environment.apiUrl}/vehiculos/mantenimientos/reporte`;

  readonly money = money;

  r = signal<ReporteMantenimiento | null>(null);
  porVehiculo = signal(false);
  error = signal('');
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
    this.http.get<ReporteMantenimiento>(this.api, { params: this.params() }).subscribe({
      next: (r) => {
        this.r.set(r);
        this.porVehiculo.set(r.filtros.agrupar === 'vehiculo');
        this.filtros.desde = r.filtros.desde;
        this.filtros.hasta = r.filtros.hasta;
      },
      error: (e) => this.error.set(e.error?.message ?? 'No se pudo consultar.')
    });
  }

  porcentaje(importe: number): number {
    const total = this.r()?.totales.total ?? 0;
    return total > 0 ? Math.round((importe / total) * 1000) / 10 : 0;
  }

  historial(vehiculo: number): void {
    HistorialVehiculoComponent.abrir(this.modal, vehiculo);
  }

  excel(): void {
    this.http
      .get(`${this.api}/excel`, { params: this.params(), responseType: 'blob' })
      .subscribe((b) => abrirBlob(b, `costo_mantenimiento_por_${this.filtros.agrupar}.xlsx`, true));
  }

  private params(): HttpParams {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(this.filtros)) {
      if (v !== '' && v !== null) params = params.set(k, v);
    }
    return params;
  }
}
