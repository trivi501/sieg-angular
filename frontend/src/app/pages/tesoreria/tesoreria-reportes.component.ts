import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { Cheques, Posicion, TesoreriaService, claseEstadoPago } from './tesoreria.service';

/**
 * Reportes de Tesorería: posición bancaria (saldo en banco, pagos en tránsito y disponible, por cuenta, banco y
 * fuente) y cheques (emitidos, entregados, cobrados y cancelados). Los movimientos están en su propia pantalla.
 */
@Component({
  selector: 'app-tesoreria-reportes',
  imports: [FormsModule, RouterLink, DatePipe, AccionComponent],
  template: `
    <div class="mb-3">
      <a routerLink="/tesoreria" class="small text-muted">← Mesa de control de pagos</a>
      <h4 class="mb-1">Reportes de Tesorería</h4>
    </div>

    <ul class="nav nav-tabs mb-3">
      <li class="nav-item">
        <button type="button" class="nav-link" [class.active]="tab() === 'posicion'" (click)="tab.set('posicion')">
          Posición bancaria
        </button>
      </li>
      <li class="nav-item">
        <button type="button" class="nav-link" [class.active]="tab() === 'cheques'" (click)="tab.set('cheques'); cargarCheques()">
          Cheques
        </button>
      </li>
      <li class="nav-item"><a routerLink="/tesoreria/movimientos" class="nav-link">Movimientos por cuenta</a></li>
    </ul>

    @if (tab() === 'posicion') {
      <div class="card">
        <div class="card-body">
          <div class="row g-2 mb-3 align-items-end">
            <div class="col-6 col-md-2">
              <label class="form-label small mb-0" for="corte">Al</label>
              <input id="corte" type="date" class="form-control form-control-sm" [(ngModel)]="corte" (ngModelChange)="cargarPosicion()" />
            </div>
            <div class="col-auto">
              <button
                type="button"
                class="btn btn-sm btn-outline-success"
                (click)="excel('posicion/excel', { corte })"
                appAccion="Exportar a Excel"
                icono="file-excel"
              ></button>
            </div>
            <small class="col-12 text-muted">
              Saldo en banco = saldo inicial + abonos − cargos del estado de cuenta. Disponible = saldo en banco − pagos ya emitidos que el
              banco todavía no refleja (en tránsito).
            </small>
          </div>
          @if (p(); as p) {
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Banco</th>
                    <th>Cuenta</th>
                    <th>Fuente</th>
                    <th class="text-end">Saldo inicial</th>
                    <th class="text-end">Abonos</th>
                    <th class="text-end">Cargos</th>
                    <th class="text-end">Saldo en banco</th>
                    <th class="text-end">En tránsito</th>
                    <th class="text-end">Disponible</th>
                  </tr>
                </thead>
                <tbody>
                  @for (c of p.cuentas; track c.id) {
                    <tr>
                      <td>{{ c.banco }}</td>
                      <td class="small">{{ c.cuenta }}</td>
                      <td>{{ c.fuente }}</td>
                      <td class="text-end font-monospace">{{ money(c.saldo_inicial) }}</td>
                      <td class="text-end font-monospace">{{ money(c.abonos) }}</td>
                      <td class="text-end font-monospace">{{ money(c.cargos) }}</td>
                      <td class="text-end font-monospace">{{ money(c.saldo_banco) }}</td>
                      <td class="text-end font-monospace" [title]="c.pagos_en_transito + ' pago(s)'">{{ money(c.en_transito) }}</td>
                      <td class="text-end font-monospace fw-semibold" [class.text-danger]="c.disponible < 0">{{ money(c.disponible) }}</td>
                    </tr>
                  } @empty {
                    <tr>
                      <td colspan="9" class="text-center text-muted py-4">
                        Sin cuentas. Dalas de alta en
                        <a routerLink="/catalogos/tesoreria-cuentas">Cuentas bancarias del Ayuntamiento</a>
                        .
                      </td>
                    </tr>
                  }
                </tbody>
                @if (p.cuentas.length) {
                  <tfoot>
                    <tr class="fw-bold">
                      <td colspan="3">Total</td>
                      <td class="text-end font-monospace">{{ money(p.totales.saldo_inicial) }}</td>
                      <td class="text-end font-monospace">{{ money(p.totales.abonos) }}</td>
                      <td class="text-end font-monospace">{{ money(p.totales.cargos) }}</td>
                      <td class="text-end font-monospace">{{ money(p.totales.saldo_banco) }}</td>
                      <td class="text-end font-monospace">{{ money(p.totales.en_transito) }}</td>
                      <td class="text-end font-monospace">{{ money(p.totales.disponible) }}</td>
                    </tr>
                  </tfoot>
                }
              </table>
            </div>

            <div class="row g-3 mt-2">
              @for (
                bloque of [
                  ['Por banco', p.por_banco],
                  ['Por fuente de financiamiento', p.por_fuente]
                ];
                track $index
              ) {
                <div class="col-md-6">
                  <h6>{{ bloque[0] }}</h6>
                  <table class="table table-sm mb-0">
                    <thead>
                      <tr>
                        <th></th>
                        <th class="text-end">Saldo en banco</th>
                        <th class="text-end">En tránsito</th>
                        <th class="text-end">Disponible</th>
                      </tr>
                    </thead>
                    <tbody>
                      @for (g of $any(bloque[1]); track g.clave) {
                        <tr>
                          <td>
                            {{ g.clave }}
                            <small class="text-muted">({{ g.cuentas }})</small>
                          </td>
                          <td class="text-end font-monospace">{{ money(g.saldo_banco) }}</td>
                          <td class="text-end font-monospace">{{ money(g.en_transito) }}</td>
                          <td class="text-end font-monospace fw-semibold">{{ money(g.disponible) }}</td>
                        </tr>
                      }
                    </tbody>
                  </table>
                </div>
              }
            </div>
          } @else {
            <p class="text-muted">Cargando…</p>
          }
        </div>
      </div>
    } @else {
      <div class="card">
        <div class="card-body">
          <div class="row g-2 mb-3 align-items-end">
            <div class="col-6 col-md-2">
              <label class="form-label small mb-0" for="desde">Desde</label>
              <input
                id="desde"
                type="date"
                class="form-control form-control-sm"
                [(ngModel)]="filtrosCheques.desde"
                (ngModelChange)="cargarCheques()"
              />
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small mb-0" for="hasta">Hasta</label>
              <input
                id="hasta"
                type="date"
                class="form-control form-control-sm"
                [(ngModel)]="filtrosCheques.hasta"
                (ngModelChange)="cargarCheques()"
              />
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label small mb-0" for="estado">Estado</label>
              <select id="estado" class="form-select form-select-sm" [(ngModel)]="filtrosCheques.estado" (ngModelChange)="cargarCheques()">
                <option value="">Todos</option>
                @for (r of c()?.resumen ?? []; track r.estado) {
                  <option [value]="r.estado">{{ r.texto }}</option>
                }
              </select>
            </div>
            <div class="col-auto">
              <button
                type="button"
                class="btn btn-sm btn-outline-success"
                (click)="excel('cheques/excel', filtrosCheques)"
                appAccion="Exportar a Excel"
                icono="file-excel"
              ></button>
            </div>
          </div>
          @if (c(); as c) {
            <div class="row g-2 mb-3">
              @for (r of c.resumen; track r.estado) {
                <div class="col-6 col-md-3">
                  <div class="border rounded p-2 small">
                    <span class="badge {{ clase(r.estado) }}">{{ r.texto }}</span>
                    <b class="d-block mt-1">
                      {{ r.cantidad }} ·
                      <span class="font-monospace">{{ money(r.importe) }}</span>
                    </b>
                  </div>
                </div>
              }
            </div>
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Cheque</th>
                    <th>Pago</th>
                    <th>Beneficiario</th>
                    <th>Cuenta</th>
                    <th class="text-end">Importe</th>
                    <th>Estado</th>
                  </tr>
                </thead>
                <tbody>
                  @for (ch of c.cheques; track ch.id) {
                    <tr>
                      <td>{{ ch.fecha | date: 'dd/MM/yyyy' }}</td>
                      <td class="fw-semibold">{{ ch.cheque_numero }}</td>
                      <td>
                        <a [routerLink]="['/tesoreria/pagos', ch.id]">{{ ch.numero }}</a>
                      </td>
                      <td class="small">{{ ch.beneficiario }}</td>
                      <td class="small text-muted">{{ ch.cuenta }}</td>
                      <td class="text-end font-monospace">{{ money(ch.importe) }}</td>
                      <td>
                        <span class="badge {{ clase(ch.estado) }}">{{ ch.estado_texto }}</span>
                      </td>
                    </tr>
                  } @empty {
                    <tr>
                      <td colspan="7" class="text-center text-muted py-4">Sin cheques.</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }
        </div>
      </div>
    }
  `,
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
  `
})
export class TesoreriaReportesComponent {
  private servicio = inject(TesoreriaService);

  readonly money = money;
  readonly clase = claseEstadoPago;

  tab = signal<'posicion' | 'cheques'>('posicion');
  corte = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' });
  p = signal<Posicion | null>(null);
  c = signal<Cheques | null>(null);
  filtrosCheques = { desde: '', hasta: '', estado: '' };

  constructor() {
    this.cargarPosicion();
  }

  cargarPosicion(): void {
    this.servicio.posicion(this.corte).subscribe((p) => this.p.set(p));
  }

  cargarCheques(): void {
    this.servicio.cheques(this.filtrosCheques).subscribe((c) => this.c.set(c));
  }

  excel(ruta: string, filtros: Record<string, string>): void {
    this.servicio.excel(ruta, filtros).subscribe((b) => abrirBlob(b, `${ruta.split('/')[0]}.xlsx`, true));
  }
}
