import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { Paginado } from 'src/app/core/api';
import { PaginacionComponent } from '../admin/paginacion.component';
import { AlmacenService, ResumenOrden, cantidad } from './almacen.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { AccionComponent } from 'src/app/shared/accion.component';

/** Bandeja del almacén: órdenes de compra asignadas con lo recibido, entregado y en existencia (SIEG: almacen/Index.tsx). */
@Component({
  selector: 'app-almacen-lista',
  imports: [AccionComponent, SelectBusquedaDirective, FormsModule, RouterLink, DatePipe, PaginacionComponent],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h4 class="mb-1">Almacén</h4>
        <p class="text-muted small mb-0">Órdenes de compra que Recursos Materiales asignó al almacén: entradas del proveedor y salidas al área, por partes.</p>
      </div>
      <a routerLink="/almacen/inventario" class="btn btn-outline-primary" appAccion="Inventario" icono="database"></a>
    </div>

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3" (ngSubmit)="cargar(1)">
          <div class="col-md-5">
            <input class="form-control form-control-sm" name="buscar" placeholder="Orden, requisición, proveedor o área" [(ngModel)]="buscar" />
          </div>
          <div class="col-md-3">
            <select class="form-select form-select-sm" name="situacion" [(ngModel)]="situacion" (ngModelChange)="cargar(1)">
              <option value="">Todas</option>
              <option value="por_recibir">Con pendiente por recibir</option>
              <option value="en_existencia">Con existencia</option>
              <option value="entregadas">Entregadas completas</option>
            </select>
          </div>
          <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary" appAccion="Buscar" icono="search"></button></div>
        </form>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Orden de compra</th>
                <th>Requisición / área</th>
                <th>Proveedor</th>
                <th class="text-end">Pedido</th>
                <th class="text-end">Recibido</th>
                <th class="text-end">Entregado</th>
                <th class="text-end">Por recibir</th>
                <th class="text-end">Existencia</th>
              </tr>
            </thead>
            <tbody>
              @for (o of datos()?.data ?? []; track o.id) {
                <tr>
                  <td>
                    <a [routerLink]="['/almacen', o.id]" class="fw-semibold">{{ o.orden }}</a>
                    <small class="d-block text-muted">asignada {{ o.asignada | date: 'dd/MM/yyyy' }}</small>
                  </td>
                  <td>
                    {{ o.requisicion }}
                    <small class="d-block text-muted">{{ o.unidad }}</small>
                  </td>
                  <td>{{ o.proveedor }}</td>
                  <td class="text-end">{{ cantidad(o.pedido) }}</td>
                  <td class="text-end">{{ cantidad(o.recibido) }}</td>
                  <td class="text-end">{{ cantidad(o.entregado) }}</td>
                  <td class="text-end" [class.text-warning]="o.por_recibir > 0">{{ cantidad(o.por_recibir) }}</td>
                  <td class="text-end fw-semibold">{{ cantidad(o.existencia) }}</td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">{{ datos() ? 'Sin órdenes de compra en el almacén.' : 'Cargando…' }}</td>
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
export class AlmacenListaComponent {
  private servicio = inject(AlmacenService);
  readonly cantidad = cantidad;

  datos = signal<Paginado<ResumenOrden> | null>(null);
  buscar = '';
  situacion = '';

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.ordenes({ buscar: this.buscar, situacion: this.situacion, page }).subscribe((d) => this.datos.set(d));
  }
}
