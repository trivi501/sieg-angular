import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { CanDirective } from 'src/app/core/auth/can.directive';
import { PaginacionComponent } from '../admin/paginacion.component';
import { RequisicionesService, UnidadCaptura } from '../requisiciones/requisiciones.service';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { ListaTramites, RUTA_TIPO, ServiciosService, TipoTramite, claseEstadoTramite } from './servicios.service';

/**
 * Lista de trámites de servicios (data.tipo = 'servicio') o de fondos revolventes (data.tipo = 'fondo'): el área
 * sube las facturas y quien tiene el permiso las valida; al validarse se generan requisición y CLC.
 */
@Component({
  selector: 'app-servicios-lista',
  imports: [FormsModule, RouterLink, DatePipe, PaginacionComponent, CanDirective, AccionComponent, SelectBusquedaDirective],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h4 class="mb-1">{{ fondo ? 'Fondos revolventes' : 'Trámites de servicios' }}</h4>
        <p class="text-muted small mb-0">
          @if (fondo) {
            Cada área sube las facturas de sus gastos menores (de distintos proveedores, hasta $2,000 cada una) y asigna la partida de cada artículo. Al validarse se
            generan la requisición y la CLC a favor del trabajador.
          } @else {
            Se suben las facturas (XML) de un mismo proveedor, se clasifica cada una y Recursos Materiales las valida. Al validarse se generan la requisición, la orden
            de compra y la CLC (un renglón por factura).
          }
        </p>
      </div>
      <button *appCan="permisoCrear" type="button" class="btn btn-primary" (click)="nuevo.set(!nuevo())" [appAccion]="fondo ? 'Nuevo fondo revolvente' : 'Nuevo trámite'" icono="plus"></button>
    </div>

    @if (nuevo()) {
      <form class="card mb-3" (ngSubmit)="crear()">
        <div class="card-body row g-3">
          <div class="col-md-5">
            <label class="form-label" for="unidad">Secretaría o dirección</label>
            <select id="unidad" name="unidad" class="form-select" [(ngModel)]="unidad" [class.is-invalid]="errores()['id_cat_egreso_unidad_administrativa']">
              <option [ngValue]="null">Seleccionar…</option>
              @for (u of unidades(); track u.id) {
                <option [ngValue]="u.id">{{ u.clave }} — {{ u.nombre }}</option>
              }
            </select>
            <div class="invalid-feedback">{{ errores()['id_cat_egreso_unidad_administrativa'] }}</div>
          </div>
          <div class="col-md-7">
            <label class="form-label" for="concepto">Concepto general</label>
            <input
              id="concepto"
              name="concepto"
              class="form-control"
              maxlength="3000"
              [placeholder]="fondo ? 'P. ej. Reposición de fondo revolvente de octubre' : 'P. ej. Servicio de mantenimiento de aires acondicionados'"
              [(ngModel)]="concepto"
              [class.is-invalid]="errores()['concepto']"
            />
            <div class="invalid-feedback">{{ errores()['concepto'] }}</div>
          </div>
          @if (fondo) {
            <div class="col-md-5">
              <label class="form-label" for="trabajador">Trabajador (a su favor va la CLC)</label>
              <input id="trabajador" name="trabajador" class="form-control" maxlength="300" placeholder="Nombre completo" [(ngModel)]="trabajador.nombre" />
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label" for="numero">No. de empleado</label>
              <input id="numero" name="numero" class="form-control" maxlength="30" [(ngModel)]="trabajador.numero" />
            </div>
            <div class="col-6 col-md-5">
              <label class="form-label" for="puesto">Puesto</label>
              <input id="puesto" name="puesto" class="form-control" maxlength="200" [(ngModel)]="trabajador.puesto" />
            </div>
          }
        </div>
        <div class="card-footer d-flex gap-2">
          <button type="submit" class="btn btn-primary" [disabled]="guardando()">{{ guardando() ? 'Creando…' : 'Crear y subir facturas' }}</button>
          <button type="button" class="btn btn-outline-secondary" (click)="nuevo.set(false)">Cancelar</button>
        </div>
      </form>
    }

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3 align-items-end" (ngSubmit)="cargar(1)">
          <div class="col-md-5">
            <input name="buscar" class="form-control form-control-sm" [placeholder]="fondo ? 'Folio, concepto o trabajador' : 'Folio, concepto, proveedor o RFC'" [(ngModel)]="filtros['buscar']" />
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
                <th>Fecha</th>
                <th>Secretaría</th>
                <th>Concepto</th>
                <th>{{ fondo ? 'Trabajador' : 'Proveedor' }}</th>
                <th class="text-center">Facturas</th>
                <th class="text-end">Importe</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              @for (t of datos()?.data ?? []; track t.id) {
                <tr role="button" (click)="abrir(t.id)">
                  <td class="fw-semibold text-nowrap"><a [routerLink]="['/' + ruta, t.id]">{{ t.folio }}</a></td>
                  <td class="text-nowrap">{{ t.fecha | date: 'dd/MM/yyyy' }}</td>
                  <td class="small text-truncate" style="max-width: 200px">{{ t.unidad }}</td>
                  <td class="small text-truncate" style="max-width: 240px">{{ t.concepto }}</td>
                  <td class="small">
                    {{ t.proveedor ?? '—' }}
                    <span class="d-block text-muted">{{ t.rfc }}</span>
                  </td>
                  <td class="text-center">{{ t.facturas }}</td>
                  <td class="text-end font-monospace">{{ money(t.importe) }}</td>
                  <td>
                    <span class="badge {{ clase(t.estado) }}">{{ t.estado_texto }}</span>
                    @if (t.requisicion) {
                      <small class="d-block text-muted">{{ t.requisicion }}</small>
                    }
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">{{ datos() ? (fondo ? 'Sin fondos revolventes.' : 'Sin trámites.') : 'Cargando…' }}</td>
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
export class ServiciosListaComponent {
  private servicio = inject(ServiciosService);
  private requisiciones = inject(RequisicionesService);
  private router = inject(Router);

  readonly tipo: TipoTramite = inject(ActivatedRoute).snapshot.data['tipo'] ?? 'servicio';
  readonly fondo = this.tipo === 'fondo';
  readonly ruta = RUTA_TIPO[this.tipo];
  readonly permisoCrear = `${this.ruta}-create`;
  readonly money = money;
  readonly clase = claseEstadoTramite;

  datos = signal<ListaTramites | null>(null);
  estados = signal<[string, string][]>([]);
  filtros: Record<string, string> = { buscar: '', estado: '' };

  // Alta
  nuevo = signal(false);
  unidades = signal<UnidadCaptura[]>([]);
  unidad: number | null = null;
  concepto = '';
  trabajador = { nombre: '', numero: '', puesto: '' };
  errores = signal<Record<string, string>>({});
  guardando = signal(false);

  constructor() {
    this.cargar(1);
    this.requisiciones.opciones().subscribe((o) => {
      this.unidades.set(o.unidades);
      this.unidad = o.unidadDefault ?? (o.unidades.length === 1 ? o.unidades[0].id : null);
    });
  }

  cargar(page: number): void {
    this.servicio.lista(this.tipo, { ...this.filtros, page }).subscribe((d) => {
      this.datos.set(d);
      this.estados.set(Object.entries(d.estados));
    });
  }

  abrir(id: number): void {
    this.router.navigate(['/' + this.ruta, id]);
  }

  crear(): void {
    this.guardando.set(true);
    this.errores.set({});
    const datos = {
      id_cat_egreso_unidad_administrativa: this.unidad,
      concepto: this.concepto,
      ...(this.fondo ? { trabajador_nombre: this.trabajador.nombre, trabajador_numero: this.trabajador.numero, trabajador_puesto: this.trabajador.puesto } : {})
    };
    this.servicio.crear(this.tipo, datos).subscribe({
      next: (r) => this.router.navigate(['/' + this.ruta, r.id], { state: { mensaje: r.message } }),
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        this.errores.set(erroresDe(e).campos);
      }
    });
  }
}
