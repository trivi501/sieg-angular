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
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { NominaFormComponent } from './nomina-form.component';
import { DatosNomina, EstadoNomina, FilaNomina, NominaService, TipoNomina, claseEstadoNomina } from './nomina.service';

/** Nómina, seguridad social, finiquitos y laudos: Recursos Humanos los captura y Control Presupuestal los valida. */
@Component({
  selector: 'app-nominas-lista',
  imports: [FormsModule, RouterLink, DatePipe, PaginacionComponent, CanDirective, AccionComponent, NominaFormComponent],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h4 class="mb-1">Nómina y pagos de personal</h4>
        <p class="text-muted small mb-0">
          Recursos Humanos importa la nómina de Nomipac (o captura un finiquito o laudo) y la envía a validar. Al validarla Control
          Presupuestal se genera una CLC por unidad administrativa con su afectación presupuestal.
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button
          *appCan="'nomina-create'"
          type="button"
          class="btn btn-outline-secondary"
          (click)="plantilla()"
          appAccion="Plantilla de importación"
          icono="file-excel"
        ></button>
        <button
          *appCan="'nomina-create'"
          type="button"
          class="btn btn-primary"
          (click)="nuevo.set(!nuevo())"
          appAccion="Nuevo trámite"
          icono="plus"
        ></button>
      </div>
    </div>

    @if (nuevo()) {
      <div class="card">
        <div class="card-body">
          <app-nomina-form
            [errores]="errores()"
            [guardando]="guardando()"
            textoGuardar="Registrar"
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
          <div class="col-md-4">
            <input
              name="buscar"
              class="form-control form-control-sm"
              placeholder="Folio, periodo, concepto, beneficiario u oficio"
              [(ngModel)]="filtros['buscar']"
            />
          </div>
          <div class="col-6 col-md-3">
            <select name="tipo" class="form-select form-select-sm" [(ngModel)]="filtros['tipo']" (ngModelChange)="cargar(1)">
              <option value="">Todos los tipos</option>
              @for (t of tipos(); track t[0]) {
                <option [value]="t[0]">{{ t[1] }}</option>
              }
            </select>
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
                <th>Concepto / periodo</th>
                <th>A favor de</th>
                <th>Pago</th>
                <th class="text-center">Conceptos</th>
                <th class="text-end">Total</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              @for (n of datos()?.data ?? []; track n.id) {
                <tr role="button" (click)="abrir(n.id)">
                  <td class="fw-semibold text-nowrap">
                    <a [routerLink]="['/nomina', n.id]">{{ n.folio }}</a>
                    <small class="d-block text-muted">{{ n.tipo_texto }}</small>
                  </td>
                  <td class="small">
                    <b>{{ n.concepto }}</b>
                    <span class="d-block text-muted">{{ n.periodo ?? n.referencia ?? '' }}</span>
                  </td>
                  <td class="small text-truncate" style="max-width: 220px">{{ n.beneficiario }}</td>
                  <td class="small text-nowrap">{{ n.fecha_pago | date: 'dd/MM/yyyy' }}</td>
                  <td class="text-center">{{ n.renglones }}</td>
                  <td class="text-end font-monospace">{{ money(n.total) }}</td>
                  <td>
                    <span class="badge {{ clase(n.estado) }}">{{ n.estado_texto }}</span>
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">{{ datos() ? 'Sin trámites de nómina.' : 'Cargando…' }}</td>
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
export class NominasListaComponent {
  private servicio = inject(NominaService);
  private router = inject(Router);

  readonly money = money;
  readonly clase = claseEstadoNomina;

  datos = signal<Paginado<FilaNomina> | null>(null);
  estados = signal<[EstadoNomina, string][]>([]);
  tipos = signal<[TipoNomina, string][]>([]);
  filtros: Record<string, string> = { buscar: '', tipo: '', estado: '' };
  nuevo = signal(false);
  errores = signal<Record<string, string>>({});
  guardando = signal(false);

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.lista({ ...this.filtros, page }).subscribe((d) => {
      this.datos.set(d);
      this.estados.set(Object.entries(d.estados) as [EstadoNomina, string][]);
      this.tipos.set(Object.entries(d.tipos) as [TipoNomina, string][]);
    });
  }

  abrir(id: number): void {
    this.router.navigate(['/nomina', id]);
  }

  plantilla(): void {
    this.servicio.plantilla().subscribe((b) => abrirBlob(b, 'plantilla_nomina.xlsx', true));
  }

  crear(datos: DatosNomina): void {
    this.guardando.set(true);
    this.errores.set({});
    this.servicio.crear(datos).subscribe({
      next: (r) => this.router.navigate(['/nomina', r.id], { state: { mensaje: r.message } }),
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
      }
    });
  }
}
