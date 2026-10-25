import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { Paginado, erroresDe } from 'src/app/core/api';
import { CanDirective } from 'src/app/core/auth/can.directive';
import { AccionComponent } from 'src/app/shared/accion.component';
import { PaginacionComponent } from '../admin/paginacion.component';
import { PadronDatosComponent } from './padron-datos.component';
import { Alertas, DatosPadron, EstadoPadron, FilaPadron, PadronService, claseEstadoPadron } from './padron.service';

/**
 * Padrón de proveedores y contratistas (Órgano Interno de Control): alertas de cédulas vencidas o por vencer y de
 * opiniones del SAT vencidas, la lista por estado y el alta de proveedores (también los de compra única).
 */
@Component({
  selector: 'app-padron-lista',
  imports: [FormsModule, RouterLink, DatePipe, PaginacionComponent, CanDirective, AccionComponent, PadronDatosComponent],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h4 class="mb-1">Padrón de proveedores y contratistas</h4>
        <p class="text-muted small mb-0">
          El Órgano Interno de Control integra el expediente de cada proveedor y emite su cédula, vigente un año. Con la cédula vencida o
          suspendido no se le asignan órdenes de compra. Los de compra única (eventuales) se identifican aparte.
        </p>
      </div>
      <button
        *appCan="'padron-gestionar'"
        type="button"
        class="btn btn-primary"
        (click)="nuevo.set(!nuevo())"
        appAccion="Registrar proveedor"
        icono="plus"
      ></button>
    </div>

    @if (nuevo()) {
      <div class="card">
        <div class="card-body">
          <app-padron-datos
            [errores]="errores()"
            [guardando]="guardando()"
            textoGuardar="Registrar en el padrón"
            (guardar)="crear($event)"
            (cerrar)="nuevo.set(false)"
          />
        </div>
      </div>
    }

    <!-- Alertas -->
    @if (a(); as a) {
      <div class="row g-2 mb-3">
        @for (c of a.conteo; track c.estado) {
          <div class="col-6 col-md-3 col-xl">
            <button
              type="button"
              class="card mb-0 w-100 text-start border-0 shadow-sm"
              [class.border-primary]="filtros['estado'] === c.estado"
              (click)="filtrar(c.estado)"
            >
              <div class="card-body py-2 small">
                <span class="badge {{ clase(c.estado) }}">{{ c.texto }}</span>
                <b class="d-block fs-5 mt-1">{{ c.cantidad }}</b>
              </div>
            </button>
          </div>
        }
      </div>
      @if (a.vencidas.length || a.por_vencer.length || a.opiniones_vencidas.length) {
        <div class="card border-warning">
          <div class="card-body small">
            <h6 class="mb-2">Alertas</h6>
            @for (p of a.vencidas; track p.id) {
              <div class="text-danger">
                ● Cédula vencida:
                <a [routerLink]="['/padron', p.id]">{{ p.nombre }}</a>
                ({{ p.rfc }}) desde el {{ p.vigencia | date: 'dd/MM/yyyy' }}
              </div>
            }
            @for (p of a.por_vencer; track p.id) {
              <div class="text-warning">
                ● Vence en {{ p.dias }} día(s):
                <a [routerLink]="['/padron', p.id]">{{ p.nombre }}</a>
                ({{ p.rfc }}) el {{ p.vigencia | date: 'dd/MM/yyyy' }}
              </div>
            }
            @for (p of a.opiniones_vencidas; track p.id) {
              <div class="text-muted">
                ● Opinión del SAT vencida o no positiva:
                <a [routerLink]="['/padron', p.id]">{{ p.nombre }}</a>
                ({{ p.rfc }})
              </div>
            }
          </div>
        </div>
      }
    }

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3 align-items-end" (ngSubmit)="cargar(1)">
          <div class="col-md-5">
            <input name="buscar" class="form-control form-control-sm" placeholder="Nombre, RFC o giro" [(ngModel)]="filtros['buscar']" />
          </div>
          <div class="col-6 col-md-3">
            <select
              name="estado"
              class="form-select form-select-sm sin-busqueda"
              [(ngModel)]="filtros['estado']"
              (ngModelChange)="cargar(1)"
            >
              <option value="">Todos</option>
              <option value="alerta">Vencidas y por vencer</option>
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
                <th>Proveedor</th>
                <th>RFC</th>
                <th>Giro</th>
                <th>Cédula</th>
                <th>Vigencia</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              @for (p of datos()?.data ?? []; track p.id) {
                <tr role="button" (click)="abrir(p.id)">
                  <td class="fw-semibold">
                    <a [routerLink]="['/padron', p.id]">{{ p.nombre }}</a>
                  </td>
                  <td>{{ p.rfc }}</td>
                  <td class="small text-truncate" style="max-width: 220px">{{ p.giro }}</td>
                  <td>{{ p.cedula ?? '—' }}</td>
                  <td class="text-nowrap">{{ p.compra_unica ? '—' : (p.vigencia | date: 'dd/MM/yyyy') }}</td>
                  <td>
                    <span class="badge {{ clase(p.estado) }}">{{ p.estado_texto }}</span>
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">{{ datos() ? 'Sin proveedores.' : 'Cargando…' }}</td>
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
export class PadronListaComponent {
  private servicio = inject(PadronService);
  private router = inject(Router);

  readonly clase = claseEstadoPadron;

  datos = signal<Paginado<FilaPadron> | null>(null);
  a = signal<Alertas | null>(null);
  estados = signal<[EstadoPadron, string][]>([]);
  filtros: Record<string, string> = { buscar: '', estado: '' };
  nuevo = signal(false);
  errores = signal<Record<string, string>>({});
  guardando = signal(false);

  constructor() {
    this.cargar(1);
    this.servicio.alertas().subscribe((a) => this.a.set(a));
  }

  cargar(page: number): void {
    this.servicio.lista({ ...this.filtros, page }).subscribe((d) => {
      this.datos.set(d);
      this.estados.set(Object.entries(d.estados) as [EstadoPadron, string][]);
    });
  }

  filtrar(estado: string): void {
    this.filtros['estado'] = this.filtros['estado'] === estado ? '' : estado;
    this.cargar(1);
  }

  abrir(id: number): void {
    this.router.navigate(['/padron', id]);
  }

  crear(datos: DatosPadron): void {
    this.guardando.set(true);
    this.errores.set({});
    this.servicio.crear(datos).subscribe({
      next: (r) => this.router.navigate(['/padron', r.id], { state: { mensaje: r.message } }),
      error: (e: HttpErrorResponse) => {
        this.guardando.set(false);
        this.errores.set(erroresDe(e).campos);
      }
    });
  }
}
