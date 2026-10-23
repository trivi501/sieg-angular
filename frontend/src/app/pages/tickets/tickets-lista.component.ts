import { Component, inject, signal } from '@angular/core';
import { DatePipe, KeyValuePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { Paginado } from 'src/app/core/api';
import { CanDirective } from 'src/app/core/auth/can.directive';
import { PaginacionComponent } from '../admin/paginacion.component';
import { ESTADOS, PRIORIDADES, Ticket, TicketsService, claseEstadoTicket, clasePrioridad } from './tickets.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { AccionComponent } from 'src/app/shared/accion.component';

/** Tickets de soporte: Admin ve todos; los demás, los suyos y los asignados (SIEG: support-tickets/Index.tsx). */
@Component({
  selector: 'app-tickets-lista',
  imports: [AccionComponent, SelectBusquedaDirective, FormsModule, RouterLink, DatePipe, KeyValuePipe, CanDirective, PaginacionComponent],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h4 class="mb-1">Tickets de soporte</h4>
        <p class="text-muted small mb-0">Reporta un problema o solicita ayuda; te avisamos cuando haya comentarios o se resuelva.</p>
      </div>
      <a *appCan="'tickets-create'" routerLink="/tickets/nuevo" class="btn btn-primary" appAccion="Nuevo ticket" icono="plus"></a>
    </div>

    <div class="card">
      <div class="card-body">
        <form class="row g-2 mb-3" (ngSubmit)="cargar(1)">
          <div class="col-md-5"><input class="form-control form-control-sm" name="search" placeholder="Buscar por título" [(ngModel)]="filtros['search']" /></div>
          <div class="col-6 col-md-2">
            <select class="form-select form-select-sm" name="status" [(ngModel)]="filtros['status']" (ngModelChange)="cargar(1)">
              <option value="">Todos los estados</option>
              @for (e of estados | keyvalue: sinOrden; track e.key) {
                <option [value]="e.key">{{ e.value }}</option>
              }
            </select>
          </div>
          <div class="col-6 col-md-2">
            <select class="form-select form-select-sm" name="priority" [(ngModel)]="filtros['priority']" (ngModelChange)="cargar(1)">
              <option value="">Todas las prioridades</option>
              @for (p of prioridades | keyvalue: sinOrden; track p.key) {
                <option [value]="p.key">{{ p.value }}</option>
              }
            </select>
          </div>
          <div class="col-auto"><button type="submit" class="btn btn-sm btn-primary" appAccion="Buscar" icono="search"></button></div>
        </form>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Título</th>
                <th>Prioridad</th>
                <th>Estado</th>
                <th>Reportó</th>
                <th>Asignado</th>
                <th>Fecha</th>
              </tr>
            </thead>
            <tbody>
              @for (t of datos()?.data ?? []; track t.id) {
                <tr>
                  <td>{{ t.id }}</td>
                  <td>
                    <a [routerLink]="['/tickets', t.id]" class="fw-semibold">{{ t.title }}</a>
                    @if (t.comments_count) {
                      <small class="text-muted ms-1">· {{ t.comments_count }} comentario(s)</small>
                    }
                  </td>
                  <td><span class="badge {{ clasePrioridad(t.priority) }}">{{ prioridades[t.priority] }}</span></td>
                  <td><span class="badge {{ claseEstado(t.status) }}">{{ estados[t.status] }}</span></td>
                  <td>{{ t.user }}</td>
                  <td>{{ t.assigned ?? '—' }}</td>
                  <td class="text-nowrap">{{ t.created_at | date: 'dd/MM/yyyy HH:mm' }}</td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">{{ datos() ? 'Sin tickets.' : 'Cargando…' }}</td>
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
export class TicketsListaComponent {
  private servicio = inject(TicketsService);

  readonly estados = ESTADOS;
  readonly prioridades = PRIORIDADES;
  readonly clasePrioridad = clasePrioridad;
  readonly claseEstado = claseEstadoTicket;
  readonly sinOrden = () => 0;

  datos = signal<Paginado<Ticket> | null>(null);
  filtros: Record<string, string> = { search: '', status: '', priority: '' };

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.lista({ ...this.filtros, page }).subscribe((d) => this.datos.set(d));
  }
}
