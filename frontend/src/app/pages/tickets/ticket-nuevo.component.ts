import { Component, inject, signal } from '@angular/core';
import { KeyValuePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { PRIORIDADES, TicketsService } from './tickets.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

/** Alta de ticket con captura de pantalla opcional (SIEG: support-tickets/Create.tsx). */
@Component({
  selector: 'app-ticket-nuevo',
  imports: [SelectBusquedaDirective, FormsModule, RouterLink, KeyValuePipe],
  template: `
    <div class="mb-3">
      <a routerLink="/tickets" class="small text-muted">← Tickets de soporte</a>
      <h4 class="mb-1 mt-1">Nuevo ticket</h4>
      <p class="text-muted small mb-0">Describe el problema; si puedes, agrega la pantalla donde ocurre y una captura.</p>
    </div>

    <form class="card" (ngSubmit)="guardar()">
      <div class="card-body">
        @if (error()) {
          <div class="alert alert-danger py-2">{{ error() }}</div>
        }
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label" for="titulo">Título</label>
            <input id="titulo" name="title" class="form-control" [(ngModel)]="title" [class.is-invalid]="errores()['title']" />
            <div class="invalid-feedback">{{ errores()['title'] }}</div>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="prioridad">Prioridad</label>
            <select id="prioridad" name="priority" class="form-select" [(ngModel)]="priority">
              @for (p of prioridades | keyvalue: sinOrden; track p.key) {
                <option [value]="p.key">{{ p.value }}</option>
              }
            </select>
          </div>
          <div class="col-12">
            <label class="form-label" for="descripcion">Descripción</label>
            <textarea id="descripcion" name="description" rows="5" class="form-control" [(ngModel)]="description" [class.is-invalid]="errores()['description']"></textarea>
            <div class="invalid-feedback">{{ errores()['description'] }}</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="url">Pantalla donde ocurre (opcional)</label>
            <input id="url" name="url" class="form-control" placeholder="p. ej. /requisiciones/12" [(ngModel)]="url" />
          </div>
          <div class="col-md-6">
            <label class="form-label" for="imagen">Captura (opcional, hasta 2 MB)</label>
            <input id="imagen" type="file" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp" (change)="elegir($event)" [class.is-invalid]="errores()['image']" />
            <div class="invalid-feedback">{{ errores()['image'] }}</div>
          </div>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <button type="submit" class="btn btn-primary" [disabled]="guardando()">{{ guardando() ? 'Enviando…' : 'Crear ticket' }}</button>
        <a routerLink="/tickets" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  `
})
export class TicketNuevoComponent {
  private servicio = inject(TicketsService);
  private router = inject(Router);

  readonly prioridades = PRIORIDADES;
  readonly sinOrden = () => 0;

  title = '';
  description = '';
  url = '';
  priority = 'media';
  imagen: File | null = null;

  errores = signal<Record<string, string>>({});
  error = signal('');
  guardando = signal(false);

  elegir(evento: Event): void {
    this.imagen = (evento.target as HTMLInputElement).files?.[0] ?? null;
  }

  guardar(): void {
    const datos = new FormData();
    datos.append('title', this.title);
    datos.append('description', this.description);
    datos.append('priority', this.priority);
    if (this.url) {
      datos.append('url', this.url);
    }
    if (this.imagen) {
      datos.append('image', this.imagen);
    }

    this.guardando.set(true);
    this.errores.set({});
    this.error.set('');
    this.servicio.crear(datos).subscribe({
      next: (r) => this.router.navigate(['/tickets', r.id], { state: { mensaje: r.message } }),
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(campos);
        this.error.set(mensaje);
        this.guardando.set(false);
      }
    });
  }
}
