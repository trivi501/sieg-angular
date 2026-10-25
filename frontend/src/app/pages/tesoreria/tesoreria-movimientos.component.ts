import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { Paginado, erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AuthService } from 'src/app/core/auth/auth.service';
import { AccionComponent } from 'src/app/shared/accion.component';
import { PaginacionComponent } from '../admin/paginacion.component';
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { CuentaTesoreria, Movimiento, TesoreriaService } from './tesoreria.service';

/**
 * Movimientos del estado de cuenta de las cuentas del Ayuntamiento: captura uno por uno o con la plantilla de Excel,
 * y conciliación automática de los pagos (un cargo con su número de transferencia o cheque y el mismo monto).
 */
@Component({
  selector: 'app-tesoreria-movimientos',
  imports: [FormsModule, RouterLink, DatePipe, PaginacionComponent, AccionComponent],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <a routerLink="/tesoreria" class="small text-muted">← Mesa de control de pagos</a>
        <h4 class="mb-1">Movimientos bancarios y conciliación</h4>
        <p class="text-muted small mb-0">
          Registra los cargos y abonos del estado de cuenta. Un pago se concilia con el cargo que trae su número (TRF o cheque) si el monto
          cuadra; si no cuadra, se avisa y no se cierra.
        </p>
      </div>
      @if (puedeConciliar) {
        <div class="d-flex flex-wrap gap-2">
          <button
            type="button"
            class="btn btn-outline-secondary"
            (click)="plantilla()"
            appAccion="Descargar plantilla"
            icono="download"
          ></button>
          <button type="button" class="btn btn-outline-primary" (click)="panel.set(panel() === 'importar' ? null : 'importar')">
            Importar estado de cuenta
          </button>
          <button type="button" class="btn btn-outline-primary" (click)="panel.set(panel() === 'captura' ? null : 'captura')">
            Capturar movimiento
          </button>
          <button type="button" class="btn btn-success" [disabled]="ocupado()" (click)="conciliarAutomatico()">
            Conciliar automáticamente
          </button>
        </div>
      }
    </div>

    @if (aviso(); as a) {
      <div class="alert alert-{{ a.tipo }} alert-dismissible py-2">
        {{ a.texto }}
        <button type="button" class="btn-close" (click)="aviso.set(null)"></button>
      </div>
    }

    @if (panel() === 'importar') {
      <div class="card">
        <div class="card-body row g-2 align-items-end">
          <div class="col-md-5">
            <label class="form-label small mb-0" for="cuenta-imp">Cuenta</label>
            <select id="cuenta-imp" class="form-select form-select-sm" [(ngModel)]="nuevo.cuenta_id">
              <option [ngValue]="null">Seleccionar…</option>
              @for (c of cuentas(); track c.id) {
                <option [ngValue]="c.id">{{ c.etiqueta }}</option>
              }
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label small mb-0" for="archivo">Estado de cuenta (Excel o CSV: Fecha, Tipo, Importe… o Cargo y Abono)</label>
            <input
              id="archivo"
              type="file"
              class="form-control form-control-sm"
              accept=".xlsx,.xls,.csv"
              (change)="archivo = $any($event.target).files?.[0] ?? null"
            />
          </div>
          <div class="col-md-2">
            <button
              type="button"
              class="btn btn-sm btn-primary w-100"
              [disabled]="ocupado() || !archivo || !nuevo.cuenta_id"
              (click)="importar()"
            >
              Importar
            </button>
          </div>
          @if (errores()['archivo']) {
            <div class="col-12 text-danger small">{{ errores()['archivo'] }}</div>
          }
          <small class="col-12 text-muted">
            Los movimientos que ya estaban registrados (misma fecha, tipo, importe y referencia) se saltan.
          </small>
        </div>
      </div>
    }

    @if (panel() === 'captura') {
      <form class="card" (ngSubmit)="guardar()">
        <div class="card-body row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label small mb-0" for="cuenta-cap">Cuenta</label>
            <select
              id="cuenta-cap"
              name="cuenta"
              class="form-select form-select-sm"
              [(ngModel)]="nuevo.cuenta_id"
              [class.is-invalid]="errores()['cuenta_id']"
            >
              <option [ngValue]="null">Seleccionar…</option>
              @for (c of cuentas(); track c.id) {
                <option [ngValue]="c.id">{{ c.etiqueta }}</option>
              }
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="fecha">Fecha</label>
            <input
              id="fecha"
              name="fecha"
              type="date"
              class="form-control form-control-sm"
              [max]="hoy"
              [(ngModel)]="nuevo.fecha"
              [class.is-invalid]="errores()['fecha']"
            />
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small mb-0" for="tipo">Tipo</label>
            <select id="tipo" name="tipo" class="form-select form-select-sm" [(ngModel)]="nuevo.tipo">
              <option value="cargo">Cargo</option>
              <option value="abono">Abono</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0" for="importe">Importe</label>
            <input
              id="importe"
              name="importe"
              type="number"
              step="0.01"
              min="0"
              class="form-control form-control-sm"
              [(ngModel)]="nuevo.importe"
              [class.is-invalid]="errores()['importe']"
            />
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0" for="referencia">Referencia</label>
            <input
              id="referencia"
              name="referencia"
              class="form-control form-control-sm"
              maxlength="100"
              placeholder="TRF-2026-0001"
              [(ngModel)]="nuevo.referencia"
            />
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0" for="rastreo">Clave de rastreo (SPEI)</label>
            <input id="rastreo" name="rastreo" class="form-control form-control-sm" maxlength="60" [(ngModel)]="nuevo.clave_rastreo" />
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0" for="concepto">Concepto</label>
            <input id="concepto" name="concepto" class="form-control form-control-sm" maxlength="500" [(ngModel)]="nuevo.concepto" />
          </div>
          <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100" [disabled]="ocupado()">Guardar</button></div>
          @if (primerError()) {
            <div class="col-12 text-danger small">{{ primerError() }}</div>
          }
        </div>
      </form>
    }

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3 align-items-end" (ngSubmit)="cargar(1)">
          <div class="col-md-3">
            <select name="f-cuenta" class="form-select form-select-sm" [(ngModel)]="filtros.cuenta_id" (ngModelChange)="cargar(1)">
              <option [ngValue]="null">Todas las cuentas</option>
              @for (c of cuentas(); track c.id) {
                <option [ngValue]="c.id">{{ c.etiqueta }}</option>
              }
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="f-tipo" class="form-select form-select-sm" [(ngModel)]="filtros.tipo" (ngModelChange)="cargar(1)">
              <option value="">Cargos y abonos</option>
              <option value="cargo">Cargos</option>
              <option value="abono">Abonos</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="f-conc" class="form-select form-select-sm" [(ngModel)]="filtros.conciliado" (ngModelChange)="cargar(1)">
              <option value="">Conciliados o no</option>
              <option value="no">Sin conciliar</option>
              <option value="si">Conciliados</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <input name="desde" type="date" class="form-control form-control-sm" [(ngModel)]="filtros.desde" />
          </div>
          <div class="col-6 col-md-2">
            <input name="hasta" type="date" class="form-control form-control-sm" [(ngModel)]="filtros.hasta" />
          </div>
          <div class="col-auto d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary" appAccion="Buscar" icono="search"></button>
            <button
              type="button"
              class="btn btn-sm btn-outline-success"
              (click)="excel()"
              appAccion="Exportar a Excel"
              icono="file-excel"
            ></button>
          </div>
        </form>

        @if (datos(); as d) {
          <div class="row g-3 small mb-2">
            <div class="col-auto">
              <span class="text-muted">Abonos</span>
              <b class="font-monospace">{{ money(d.totales.abonos) }}</b>
            </div>
            <div class="col-auto">
              <span class="text-muted">Cargos</span>
              <b class="font-monospace">{{ money(d.totales.cargos) }}</b>
            </div>
          </div>
        }
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Cuenta</th>
                <th>Referencia / rastreo</th>
                <th>Concepto</th>
                <th class="text-end">Abono</th>
                <th class="text-end">Cargo</th>
                <th>Pago</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              @for (m of datos()?.data ?? []; track m.id) {
                <tr>
                  <td class="text-nowrap">{{ m.fecha | date: 'dd/MM/yyyy' }}</td>
                  <td class="small text-muted">{{ m.cuenta }}</td>
                  <td class="small">
                    {{ m.referencia }}
                    <span class="d-block text-muted">{{ m.clave_rastreo }}</span>
                  </td>
                  <td class="small">{{ m.concepto }}</td>
                  <td class="text-end font-monospace">{{ m.tipo === 'abono' ? money(m.importe) : '' }}</td>
                  <td class="text-end font-monospace">{{ m.tipo === 'cargo' ? money(m.importe) : '' }}</td>
                  <td>
                    @if (m.pago_id) {
                      <a [routerLink]="['/tesoreria/pagos', m.pago_id]" class="badge bg-light-success text-success">{{ m.pago }}</a>
                    } @else if (m.tipo === 'cargo') {
                      <span class="badge bg-light-warning text-warning">Sin conciliar</span>
                    }
                  </td>
                  <td class="text-end">
                    @if (puedeConciliar && !m.pago_id) {
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        (click)="eliminar(m)"
                        appAccion="Eliminar movimiento"
                        icono="delete"
                      ></button>
                    }
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">{{ datos() ? 'Sin movimientos.' : 'Cargando…' }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
        @if (datos(); as d) {
          <app-paginacion [actual]="d.current_page" [ultima]="d.last_page" [total]="d.total" (cambiar)="cargar($event)" />
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
export class TesoreriaMovimientosComponent {
  private servicio = inject(TesoreriaService);

  readonly money = money;
  readonly hoy = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' });
  readonly puedeConciliar = inject(AuthService).can('tesoreria-conciliar');

  datos = signal<(Paginado<Movimiento> & { totales: { abonos: number; cargos: number } }) | null>(null);
  cuentas = signal<CuentaTesoreria[]>([]);
  aviso = signal<{ tipo: 'success' | 'danger' | 'warning'; texto: string } | null>(null);
  panel = signal<'importar' | 'captura' | null>(null);
  ocupado = signal(false);
  errores = signal<Record<string, string>>({});
  archivo: File | null = null;
  filtros: { cuenta_id: number | null; tipo: string; conciliado: string; desde: string; hasta: string } = {
    cuenta_id: null,
    tipo: '',
    conciliado: '',
    desde: '',
    hasta: ''
  };
  nuevo = this.vacio();

  constructor() {
    this.cargar(1);
  }

  private vacio() {
    return {
      cuenta_id: null as number | null,
      fecha: this.hoy,
      tipo: 'cargo',
      importe: null as number | null,
      referencia: '',
      clave_rastreo: '',
      concepto: ''
    };
  }

  primerError(): string {
    return Object.values(this.errores())[0] ?? '';
  }

  cargar(page: number): void {
    this.servicio.movimientos({ ...this.filtros, page }).subscribe((d) => {
      this.datos.set(d);
      this.cuentas.set(d.cuentas);
      if (!this.nuevo.cuenta_id && d.cuentas.length === 1) this.nuevo.cuenta_id = d.cuentas[0].id;
    });
  }

  guardar(): void {
    this.ocupado.set(true);
    this.errores.set({});
    this.servicio.guardarMovimiento(this.nuevo).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        const cuenta = this.nuevo.cuenta_id;
        this.nuevo = { ...this.vacio(), cuenta_id: cuenta };
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) => this.fallo(e)
    });
  }

  importar(): void {
    if (!this.archivo || !this.nuevo.cuenta_id) return;
    this.ocupado.set(true);
    this.errores.set({});
    this.servicio.importarMovimientos(this.nuevo.cuenta_id, this.archivo).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.panel.set(null);
        this.archivo = null;
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) => this.fallo(e)
    });
  }

  eliminar(m: Movimiento): void {
    if (!confirm(`¿Eliminar el ${m.tipo} del ${m.fecha} por ${money(m.importe)}?`)) return;
    this.servicio.eliminarMovimiento(m.id).subscribe({
      next: (r) => {
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar(this.datos()?.current_page ?? 1);
      },
      error: (e: HttpErrorResponse) => this.aviso.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }

  conciliarAutomatico(): void {
    this.ocupado.set(true);
    this.servicio.conciliarAutomatico(this.filtros.cuenta_id).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.aviso.set({ tipo: r.con_diferencia.length ? 'warning' : 'success', texto: r.message });
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) => this.fallo(e)
    });
  }

  plantilla(): void {
    this.servicio.excel('movimientos/plantilla').subscribe((b) => abrirBlob(b, 'plantilla_movimientos.xlsx', true));
  }

  excel(): void {
    this.servicio.excel('movimientos/excel', this.filtros).subscribe((b) => abrirBlob(b, 'movimientos_bancarios.xlsx', true));
  }

  private fallo(e: HttpErrorResponse): void {
    this.ocupado.set(false);
    const { campos, mensaje } = erroresDe(e);
    this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
    if (mensaje) this.aviso.set({ tipo: 'danger', texto: mensaje });
  }
}
