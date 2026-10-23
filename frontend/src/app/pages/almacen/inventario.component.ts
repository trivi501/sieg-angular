import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { Paginado } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { PaginacionComponent } from '../admin/paginacion.component';
import { AlmacenService, FilaInventario, cantidad } from './almacen.service';
import { AccionComponent } from 'src/app/shared/accion.component';

/** Inventario del almacén: por concepto recibido, lo entregado y la existencia valuada al precio de la orden (SIEG: Inventario.tsx). */
@Component({
  selector: 'app-inventario',
  imports: [AccionComponent, FormsModule, RouterLink, PaginacionComponent],
  template: `
    <div class="mb-3">
      <a routerLink="/almacen" class="small text-muted">← Almacén</a>
      <h4 class="mb-1 mt-1">Inventario</h4>
      <p class="text-muted small mb-0">Lo recibido en el almacén por concepto: entregado al área y existencia, valuada al precio de la orden de compra.</p>
    </div>

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3 align-items-center" (ngSubmit)="cargar(1)">
          <div class="col-md-5">
            <input class="form-control form-control-sm" name="buscar" placeholder="Concepto, orden, requisición, área o proveedor" [(ngModel)]="buscar" />
          </div>
          <div class="col-auto">
            <label class="form-check-label small">
              <input type="checkbox" class="form-check-input me-1" name="todo" [(ngModel)]="todo" (ngModelChange)="cargar(1)" />
              Incluir lo ya entregado completo
            </label>
          </div>
          <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary" appAccion="Buscar" icono="search"></button></div>
        </form>

        @if (datos(); as d) {
          <p class="small text-muted mb-2">Existencia {{ cantidad(d.totales.existencia) }} unidades · valor {{ money(d.totales.valor) }}</p>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Concepto</th>
                  <th>Orden / requisición</th>
                  <th>Área</th>
                  <th class="text-end">Recibido</th>
                  <th class="text-end">Entregado</th>
                  <th class="text-end">Existencia</th>
                  <th class="text-end">P. unitario</th>
                  <th class="text-end">Valor</th>
                </tr>
              </thead>
              <tbody>
                @for (c of d.data; track c.orden_id + '-' + c.detalle_id) {
                  <tr>
                    <td>{{ c.descripcion }}</td>
                    <td>
                      <a [routerLink]="['/almacen', c.orden_id]">{{ c.orden }}</a>
                      <small class="d-block text-muted">{{ c.requisicion }}</small>
                    </td>
                    <td class="small">{{ c.unidad }}</td>
                    <td class="text-end">{{ cantidad(c.recibido) }}</td>
                    <td class="text-end">{{ cantidad(c.entregado) }}</td>
                    <td class="text-end fw-semibold">{{ cantidad(c.existencia) }}</td>
                    <td class="text-end font-monospace">{{ money(c.precio_unitario) }}</td>
                    <td class="text-end font-monospace">{{ money(c.valor) }}</td>
                  </tr>
                } @empty {
                  <tr>
                    <td colspan="8" class="text-center text-muted py-4">Sin existencias.</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
          <app-paginacion [actual]="d.current_page" [ultima]="d.last_page" [total]="d.total" (cambiar)="cargar($event)" />
        } @else {
          <p class="text-muted mb-0">Cargando…</p>
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
export class InventarioComponent {
  private servicio = inject(AlmacenService);
  readonly money = money;
  readonly cantidad = cantidad;

  datos = signal<(Paginado<FilaInventario> & { totales: { existencia: number; valor: number } }) | null>(null);
  buscar = '';
  todo = false;

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.inventario({ buscar: this.buscar, todo: this.todo, page }).subscribe((d) => this.datos.set(d));
  }
}
