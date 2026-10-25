import { Component, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { ClcService } from '../clc/clc.service';
import { ClcMesa, GrupoBeneficiario, Mesa, TesoreriaService } from './tesoreria.service';

type Aviso = { tipo: 'success' | 'danger' | 'warning'; texto: string };

/**
 * Mesa de control de pagos (Egresos): recibe las CLC que le envía Control Presupuestal, las muestra por
 * beneficiario con su total a pagar, verifica sus facturas en el SAT y genera el pago: uno (TRF) por proveedor con
 * todas sus CLC seleccionadas, por transferencia o cheque, desde una cuenta del Ayuntamiento.
 */
@Component({
  selector: 'app-tesoreria-mesa',
  imports: [FormsModule, RouterLink, DatePipe, AccionComponent],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h4 class="mb-1">Mesa de control de pagos</h4>
        <p class="text-muted small mb-0">
          CLC que llegan a Egresos, por proveedor. Se seleccionan las que se van a pagar y se genera un pago por proveedor (un número de
          transferencia interno para todas sus CLC). Después se concilia con el estado de cuenta.
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a routerLink="/tesoreria/pagos" class="btn btn-outline-secondary">Pagos</a>
        <a routerLink="/tesoreria/movimientos" class="btn btn-outline-secondary">Movimientos y conciliación</a>
        <a routerLink="/tesoreria/reportes" class="btn btn-outline-secondary">Reportes</a>
      </div>
    </div>

    @if (aviso(); as a) {
      <div class="alert alert-{{ a.tipo }} alert-dismissible py-2">
        {{ a.texto }}
        <button type="button" class="btn-close" (click)="aviso.set(null)"></button>
      </div>
    }

    @if (m(); as m) {
      <div class="row g-3 small mb-3">
        <div class="col-6 col-md-3">
          <div class="card mb-0">
            <div class="card-body py-2">
              <span class="text-muted d-block">Por recibir</span>
              <b class="fs-5">{{ m.totales.por_recibir }}</b>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card mb-0">
            <div class="card-body py-2">
              <span class="text-muted d-block">CLC por pagar</span>
              <b class="fs-5">{{ m.totales.por_pagar }}</b>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-6">
          <div class="card mb-0">
            <div class="card-body py-2">
              <span class="text-muted d-block">Total a pagar</span>
              <b class="fs-5 font-monospace">{{ money(m.totales.neto) }}</b>
            </div>
          </div>
        </div>
      </div>

      <!-- Por recibir: enviadas a Egresos -->
      @if (m.por_recibir.length) {
        <div class="card border-warning">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
              <h6 class="mb-0">Por recibir ({{ m.por_recibir.length }})</h6>
              @if (m.puede.recibir) {
                <button type="button" class="btn btn-sm btn-warning" [disabled]="!porRecibir().length || ocupado()" (click)="recibir()">
                  Recibir {{ porRecibir().length || '' }} seleccionada(s)
                </button>
              }
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width: 30px">
                      <input
                        type="checkbox"
                        class="form-check-input"
                        [checked]="porRecibir().length === m.por_recibir.length"
                        (change)="todasPorRecibir($any($event.target).checked)"
                      />
                    </th>
                    <th>CLC</th>
                    <th>Beneficiario</th>
                    <th>Secretaría</th>
                    <th class="text-end">A pagar</th>
                  </tr>
                </thead>
                <tbody>
                  @for (c of m.por_recibir; track c.id) {
                    <tr>
                      <td>
                        <input
                          type="checkbox"
                          class="form-check-input"
                          [checked]="porRecibir().includes(c.id)"
                          (change)="alternarRecibir(c.id, $any($event.target).checked)"
                        />
                      </td>
                      <td class="fw-semibold">{{ c.clc }}</td>
                      <td>{{ c.beneficiario }}</td>
                      <td class="small text-muted">{{ c.unidad }}</td>
                      <td class="text-end font-monospace">{{ money(c.neto) }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          </div>
        </div>
      }

      <!-- Pago de lo seleccionado -->
      @if (seleccion().length && m.puede.pagar) {
        <div class="card border-primary sticky-top" style="top: 70px; z-index: 5">
          <div class="card-body">
            <h6 class="mb-2">
              Pagar {{ seleccion().length }} CLC · {{ money(totalSeleccion()) }}
              <small class="text-muted">
                ({{ beneficiariosSeleccion() }}
                {{ beneficiariosSeleccion() === 1 ? 'proveedor: un pago' : 'proveedores: un pago por cada uno' }})
              </small>
            </h6>
            <div class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label small mb-0" for="cuenta">Cuenta de la que sale</label>
                <select
                  id="cuenta"
                  class="form-select form-select-sm"
                  [(ngModel)]="pago.cuenta_id"
                  [class.is-invalid]="errores()['cuenta_id']"
                >
                  <option [ngValue]="null">Seleccionar…</option>
                  @for (c of m.cuentas; track c.id) {
                    <option [ngValue]="c.id">{{ c.etiqueta }}{{ c.fuente ? ' · fuente ' + c.fuente : '' }}</option>
                  }
                </select>
                <div class="invalid-feedback">{{ errores()['cuenta_id'] }}</div>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="forma">Forma</label>
                <select id="forma" class="form-select form-select-sm" [(ngModel)]="pago.pago_forma">
                  <option value="transferencia">Transferencia</option>
                  <option value="cheque">Cheque</option>
                </select>
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="fecha">Fecha</label>
                <input
                  id="fecha"
                  type="date"
                  class="form-control form-control-sm"
                  [max]="hoy"
                  [(ngModel)]="pago.pago_fecha"
                  [class.is-invalid]="errores()['pago_fecha']"
                />
                <div class="invalid-feedback">{{ errores()['pago_fecha'] }}</div>
              </div>
              <div class="col-md-2">
                <label class="form-label small mb-0" for="ref">
                  {{ pago.pago_forma === 'cheque' ? 'Número de cheque' : 'Referencia (opcional)' }}
                </label>
                <input
                  id="ref"
                  class="form-control form-control-sm"
                  maxlength="100"
                  [(ngModel)]="pago.pago_referencia"
                  [class.is-invalid]="errores()['pago_referencia']"
                />
                <div class="invalid-feedback">{{ errores()['pago_referencia'] }}</div>
              </div>
              <div class="col-md-2 d-flex gap-1">
                <button type="button" class="btn btn-sm btn-primary flex-grow-1" [disabled]="ocupado()" (click)="pagar()">
                  {{ ocupado() ? 'Verificando…' : 'Pagar' }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  (click)="seleccion.set([])"
                  appAccion="Quitar selección"
                  icono="close"
                ></button>
              </div>
            </div>
            @if (errores()['ordenes'] || errores()['_general']) {
              <div class="text-danger small mt-2">{{ errores()['ordenes'] ?? errores()['_general'] }}</div>
            }
            <small class="text-muted d-block mt-1">
              Antes de pagar se vuelve a consultar cada factura en el SAT; una cancelada o no encontrada detiene todo el pago.
            </small>
          </div>
        </div>
      }

      <!-- Por pagar, por beneficiario -->
      <div class="card">
        <div class="card-body">
          <form class="row g-2 mb-3 align-items-end" (ngSubmit)="cargar()">
            <div class="col-md-5">
              <input
                name="buscar"
                class="form-control form-control-sm"
                placeholder="CLC, orden, proveedor o RFC"
                [(ngModel)]="filtros.buscar"
              />
            </div>
            <div class="col-6 col-md-3">
              <select name="fuente" class="form-select form-select-sm" [(ngModel)]="filtros.fuente" (ngModelChange)="cargar()">
                <option value="">Todas las fuentes</option>
                @for (f of m.fuentes; track f) {
                  <option [value]="f">Fuente {{ f }}</option>
                }
              </select>
            </div>
            <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary" appAccion="Buscar" icono="search"></button></div>
          </form>

          @for (g of m.beneficiarios; track g.clave) {
            <div class="border rounded mb-3">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 bg-light">
                <div>
                  <input
                    type="checkbox"
                    class="form-check-input me-2"
                    [checked]="grupoCompleto(g)"
                    (change)="alternarGrupo(g, $any($event.target).checked)"
                  />
                  <b>{{ g.beneficiario }}</b>
                  <span class="text-muted small ms-2">{{ g.rfc }}</span>
                  <span class="d-block small text-muted ms-4">
                    {{ g.clc }} CLC · fuente(s) {{ g.fuentes.join(', ') || '—' }} ·
                    {{ g.cuenta_destino ? 'CLABE ' + g.cuenta_destino : 'sin CLABE registrada' }}
                  </span>
                </div>
                <div class="text-end">
                  <span class="text-muted small d-block">A pagar</span>
                  <b class="font-monospace">{{ money(g.neto) }}</b>
                  @if (g.isr > 0) {
                    <small class="d-block text-muted">ISR retenido {{ money(g.isr) }}</small>
                  }
                </div>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th style="width: 30px"></th>
                      <th>CLC</th>
                      <th>Orden / requisición</th>
                      <th>Secretaría</th>
                      <th>Fuente</th>
                      <th class="text-end">Importe</th>
                      <th class="text-end">A pagar</th>
                      <th>SAT</th>
                    </tr>
                  </thead>
                  <tbody>
                    @for (c of g.ordenes; track c.id) {
                      <tr>
                        <td>
                          <input
                            type="checkbox"
                            class="form-check-input"
                            [checked]="seleccion().includes(c.id)"
                            (change)="alternar(c.id, $any($event.target).checked)"
                          />
                        </td>
                        <td class="fw-semibold text-nowrap">
                          {{ c.clc }}
                          <small class="d-block text-muted">{{ c.clc_fecha | date: 'dd/MM/yyyy' }}</small>
                        </td>
                        <td class="small">
                          {{ c.orden }}
                          @if (c.requisicion_id) {
                            <a class="d-block" [routerLink]="['/requisiciones', c.requisicion_id]">{{ c.requisicion }}</a>
                          }
                        </td>
                        <td class="small text-truncate" style="max-width: 220px" [title]="c.concepto ?? ''">{{ c.unidad }}</td>
                        <td class="small">{{ c.fuentes.join(', ') || '—' }}</td>
                        <td class="text-end font-monospace">{{ money(c.importe) }}</td>
                        <td class="text-end font-monospace">{{ money(c.neto) }}</td>
                        <td class="text-nowrap">
                          <span
                            class="badge"
                            [class]="c.sat_estado === 'Vigente' ? 'bg-light-success text-success' : 'bg-light-secondary text-secondary'"
                          >
                            {{ c.sat_estado ?? 'Sin verificar' }}
                          </span>
                          <button
                            type="button"
                            class="btn btn-sm btn-link p-0 ms-1"
                            [disabled]="verificando() === c.id"
                            (click)="verificar(c)"
                            appAccion="Verificar en el SAT"
                            icono="safety-certificate"
                          ></button>
                        </td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
            </div>
          } @empty {
            <p class="text-center text-muted py-4 mb-0">
              No hay CLC por pagar{{ filtros.fuente || filtros.buscar ? ' con esos filtros' : '' }}.
            </p>
          }
        </div>
      </div>
    } @else {
      <p class="text-muted">Cargando…</p>
    }
  `,
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
  `
})
export class TesoreriaMesaComponent {
  private servicio = inject(TesoreriaService);
  private clc = inject(ClcService);
  private router = inject(Router);

  readonly money = money;
  readonly hoy = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' });

  m = signal<Mesa | null>(null);
  aviso = signal<Aviso | null>(null);
  ocupado = signal(false);
  verificando = signal<number | null>(null);
  errores = signal<Record<string, string>>({});
  filtros = { buscar: '', fuente: '' };

  porRecibir = signal<number[]>([]);
  seleccion = signal<number[]>([]);
  pago = { cuenta_id: null as number | null, pago_forma: 'transferencia', pago_fecha: this.hoy, pago_referencia: '' };

  private porId = computed(
    () => new Map((this.m()?.beneficiarios ?? []).flatMap((g) => g.ordenes.map((o) => [o.id, o] as [number, ClcMesa])))
  );

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.mesa(this.filtros).subscribe((m) => {
      this.m.set(m);
      if (!this.pago.cuenta_id && m.cuentas.length === 1) this.pago.cuenta_id = m.cuentas[0].id;
      // La selección se queda solo con lo que sigue por pagar / por recibir.
      this.seleccion.update((s) => s.filter((id) => this.porId().has(id)));
      this.porRecibir.update((s) => s.filter((id) => m.por_recibir.some((c) => c.id === id)));
    });
  }

  // --- Por recibir ---

  alternarRecibir(id: number, valor: boolean): void {
    this.porRecibir.update((s) => (valor ? [...s, id] : s.filter((x) => x !== id)));
  }
  todasPorRecibir(valor: boolean): void {
    this.porRecibir.set(valor ? (this.m()?.por_recibir ?? []).map((c) => c.id) : []);
  }
  recibir(): void {
    this.ocupado.set(true);
    this.clc.accion({ accion: 'recibir_egresos', ordenes: this.porRecibir() }).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.porRecibir.set([]);
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.aviso.set({ tipo: 'danger', texto: Object.values(campos)[0] ?? mensaje });
      }
    });
  }

  // --- Por pagar ---

  alternar(id: number, valor: boolean): void {
    this.seleccion.update((s) => (valor ? [...s, id] : s.filter((x) => x !== id)));
  }
  alternarGrupo(g: GrupoBeneficiario, valor: boolean): void {
    const ids = g.ordenes.map((o) => o.id);
    this.seleccion.update((s) => (valor ? [...new Set([...s, ...ids])] : s.filter((x) => !ids.includes(x))));
  }
  grupoCompleto(g: GrupoBeneficiario): boolean {
    return g.ordenes.every((o) => this.seleccion().includes(o.id));
  }
  totalSeleccion(): number {
    return this.seleccion().reduce((t, id) => t + (this.porId().get(id)?.neto ?? 0), 0);
  }
  beneficiariosSeleccion(): number {
    return new Set(this.seleccion().map((id) => this.porId().get(id)?.beneficiario_clave)).size;
  }

  verificar(c: ClcMesa): void {
    this.verificando.set(c.id);
    this.servicio.verificarSat(c.id).subscribe({
      next: (r) => {
        this.verificando.set(null);
        this.aviso.set({ tipo: r.vigente ? 'success' : 'warning', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.verificando.set(null);
        this.aviso.set({ tipo: 'danger', texto: erroresDe(e).mensaje || (e.error?.message ?? 'No se pudo verificar.') });
        this.cargar();
      }
    });
  }

  pagar(): void {
    if (this.pago.pago_forma === 'cheque' && this.beneficiariosSeleccion() > 1) {
      this.errores.set({ ordenes: 'Un cheque es para un solo beneficiario: selecciona CLC de un mismo proveedor.' });
      return;
    }
    this.ocupado.set(true);
    this.errores.set({});
    this.servicio.pagar({ ordenes: this.seleccion(), ...this.pago }).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.seleccion.set([]);
        this.pago.pago_referencia = '';
        if (r.pagos.length === 1) {
          this.router.navigate(['/tesoreria/pagos', r.pagos[0].id], { state: { mensaje: r.message } });
          return;
        }
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
      }
    });
  }
}
