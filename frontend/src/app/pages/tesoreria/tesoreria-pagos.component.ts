import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { Paginado } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { PaginacionComponent } from '../admin/paginacion.component';
import { CuentaTesoreria, EstadoPago, Pago, TesoreriaService, claseEstadoPago } from './tesoreria.service';

/** Pagos de Tesorería (TRF): por estado (en tránsito, conciliados, cancelados), forma, cuenta y fecha. */
@Component({
  selector: 'app-tesoreria-pagos',
  imports: [FormsModule, RouterLink, DatePipe, PaginacionComponent, AccionComponent],
  template: `
    <div class="mb-3">
      <a routerLink="/tesoreria" class="small text-muted">← Mesa de control de pagos</a>
      <h4 class="mb-1">Pagos</h4>
      <p class="text-muted small mb-0">
        Cada pago agrupa las CLC de un proveedor. "En tránsito": ya salió y el banco todavía no lo refleja (falta conciliarlo).
      </p>
    </div>

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3 align-items-end" (ngSubmit)="cargar(1)">
          <div class="col-md-3">
            <input
              name="buscar"
              class="form-control form-control-sm"
              placeholder="TRF, beneficiario o cheque"
              [(ngModel)]="filtros.buscar"
            />
          </div>
          <div class="col-6 col-md-2">
            <select name="estado" class="form-select form-select-sm" [(ngModel)]="filtros.estado" (ngModelChange)="cargar(1)">
              <option value="">Todos los estados</option>
              <option value="transito">En tránsito (sin conciliar)</option>
              @for (e of estados(); track e[0]) {
                <option [value]="e[0]">{{ e[1] }}</option>
              }
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select name="forma" class="form-select form-select-sm" [(ngModel)]="filtros.forma" (ngModelChange)="cargar(1)">
              <option value="">Transferencia y cheque</option>
              <option value="transferencia">Transferencias</option>
              <option value="cheque">Cheques</option>
            </select>
          </div>
          <div class="col-md-3">
            <select name="cuenta" class="form-select form-select-sm" [(ngModel)]="filtros.cuenta_id" (ngModelChange)="cargar(1)">
              <option [ngValue]="null">Todas las cuentas</option>
              @for (c of cuentas(); track c.id) {
                <option [ngValue]="c.id">{{ c.etiqueta }}</option>
              }
            </select>
          </div>
          <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary" appAccion="Buscar" icono="search"></button></div>
        </form>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Pago</th>
                <th>Fecha</th>
                <th>Beneficiario</th>
                <th>Forma</th>
                <th>Cuenta</th>
                <th class="text-center">CLC</th>
                <th class="text-end">Importe</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              @for (p of datos()?.data ?? []; track p.id) {
                <tr role="button" (click)="abrir(p.id)">
                  <td class="fw-semibold text-nowrap">
                    <a [routerLink]="['/tesoreria/pagos', p.id]">{{ p.numero }}</a>
                  </td>
                  <td class="text-nowrap">{{ p.fecha | date: 'dd/MM/yyyy' }}</td>
                  <td class="small">{{ p.beneficiario }}</td>
                  <td class="small">{{ p.forma_texto }}{{ p.cheque_numero ? ' ' + p.cheque_numero : '' }}</td>
                  <td class="small text-muted">{{ p.cuenta }}</td>
                  <td class="text-center">{{ p.clc }}</td>
                  <td class="text-end font-monospace">{{ money(p.importe) }}</td>
                  <td>
                    <span class="badge {{ clase(p.estado) }}">{{ p.estado_texto }}</span>
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">{{ datos() ? 'Sin pagos.' : 'Cargando…' }}</td>
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
      font-size: 13px;
    }
  `
})
export class TesoreriaPagosComponent {
  private servicio = inject(TesoreriaService);
  private router = inject(Router);

  readonly money = money;
  readonly clase = claseEstadoPago;

  datos = signal<Paginado<Pago> | null>(null);
  estados = signal<[EstadoPago, string][]>([]);
  cuentas = signal<CuentaTesoreria[]>([]);
  filtros: { buscar: string; estado: string; forma: string; cuenta_id: number | null } = {
    buscar: '',
    estado: '',
    forma: '',
    cuenta_id: null
  };

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.pagos({ ...this.filtros, page }).subscribe((d) => {
      this.datos.set(d);
      this.estados.set(Object.entries(d.estados) as [EstadoPago, string][]);
      this.cuentas.set(d.cuentas);
    });
  }

  abrir(id: number): void {
    this.router.navigate(['/tesoreria/pagos', id]);
  }
}
