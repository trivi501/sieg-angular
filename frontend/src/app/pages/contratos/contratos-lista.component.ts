import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { Paginado, erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { CanDirective } from 'src/app/core/auth/can.directive';
import { AccionComponent } from 'src/app/shared/accion.component';
import { PaginacionComponent } from '../admin/paginacion.component';
import { ContratoFormComponent } from './contrato-form.component';
import { ContratosService, DatosContrato, EstadoContrato, FilaContrato, claseEstadoContrato } from './contratos.service';

/** Arrendamientos y servicios por contrato: cada mes, con el XML de la mensualidad, se generan requisición, orden de compra y CLC. */
@Component({
  selector: 'app-contratos-lista',
  imports: [FormsModule, RouterLink, DatePipe, PaginacionComponent, CanDirective, AccionComponent, ContratoFormComponent],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h4 class="mb-1">Arrendamientos y servicios por contrato</h4>
        <p class="text-muted small mb-0">
          El área registra el contrato y Recursos Materiales lo autoriza una vez. Cada mes se carga el XML de la mensualidad y se generan
          solas la requisición, la orden de compra y la CLC, hasta la fecha de término del contrato.
        </p>
      </div>
      <button
        *appCan="'contratos-create'"
        type="button"
        class="btn btn-primary"
        (click)="nuevo.set(!nuevo())"
        appAccion="Nuevo contrato"
        icono="plus"
      ></button>
    </div>

    @if (nuevo()) {
      <div class="card">
        <div class="card-body">
          <app-contrato-form
            [errores]="errores()"
            [guardando]="guardando()"
            textoGuardar="Registrar contrato"
            (guardar)="crear($event)"
            (cerrar)="nuevo.set(false)"
          />
          @if (errores()['_general']) {
            <div class="text-danger small mt-2">{{ errores()['_general'] }}</div>
          }
        </div>
      </div>
    }

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3 align-items-end" (ngSubmit)="cargar(1)">
          <div class="col-md-5">
            <input
              name="buscar"
              class="form-control form-control-sm"
              placeholder="Folio, número de contrato, concepto o proveedor"
              [(ngModel)]="filtros['buscar']"
            />
          </div>
          <div class="col-6 col-md-3">
            <select name="estado" class="form-select form-select-sm" [(ngModel)]="filtros['estado']" (ngModelChange)="cargar(1)">
              <option value="">Todos los estados</option>
              @for (e of estados(); track e[0]) {
                <option [value]="e[0]">{{ e[1] }}</option>
              }
            </select>
          </div>
          <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary" appAccion="Buscar" icono="search"></button></div>
        </form>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Proveedor / concepto</th>
                <th>Secretaría</th>
                <th>Vigencia</th>
                <th class="text-end">Mensual</th>
                <th class="text-center">Meses</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              @for (c of datos()?.data ?? []; track c.id) {
                <tr role="button" (click)="abrir(c.id)">
                  <td class="fw-semibold text-nowrap">
                    <a [routerLink]="['/contratos', c.id]">{{ c.folio }}</a>
                    <small class="d-block text-muted">{{ c.tipo }}{{ c.numero_contrato ? ' · ' + c.numero_contrato : '' }}</small>
                  </td>
                  <td class="small">
                    <b>{{ c.proveedor }}</b>
                    <span class="d-block text-muted text-truncate" style="max-width: 280px">{{ c.concepto }}</span>
                  </td>
                  <td class="small text-truncate" style="max-width: 180px">{{ c.unidad }}</td>
                  <td class="small text-nowrap">{{ c.fecha_inicio | date: 'dd/MM/yyyy' }} al {{ c.fecha_fin | date: 'dd/MM/yyyy' }}</td>
                  <td class="text-end font-monospace">{{ money(c.monto_mensual) }}</td>
                  <td class="text-center">{{ c.facturados }} / {{ c.meses }}</td>
                  <td>
                    <span class="badge {{ clase(c.estado) }}">{{ c.estado_texto }}</span>
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">{{ datos() ? 'Sin contratos.' : 'Cargando…' }}</td>
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
export class ContratosListaComponent {
  private servicio = inject(ContratosService);
  private router = inject(Router);

  readonly money = money;
  readonly clase = claseEstadoContrato;

  datos = signal<Paginado<FilaContrato> | null>(null);
  estados = signal<[EstadoContrato, string][]>([]);
  filtros: Record<string, string> = { buscar: '', estado: '' };
  nuevo = signal(false);
  errores = signal<Record<string, string>>({});
  guardando = signal(false);

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.lista({ ...this.filtros, page }).subscribe((d) => {
      this.datos.set(d);
      this.estados.set(Object.entries(d.estados) as [EstadoContrato, string][]);
    });
  }

  abrir(id: number): void {
    this.router.navigate(['/contratos', id]);
  }

  crear(datos: DatosContrato): void {
    this.guardando.set(true);
    this.errores.set({});
    this.servicio.crear(datos).subscribe({
      next: (r) => this.router.navigate(['/contratos', r.id], { state: { mensaje: r.message } }),
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
      }
    });
  }
}
