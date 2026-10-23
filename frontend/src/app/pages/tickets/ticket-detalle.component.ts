import { Component, inject, signal } from '@angular/core';
import { DatePipe, KeyValuePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { AuthService } from 'src/app/core/auth/auth.service';
import { DetalleTicket, ESTADOS, PRIORIDADES, TicketsService, claseEstadoTicket, clasePrioridad } from './tickets.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { AccionComponent } from 'src/app/shared/accion.component';

/** Un ticket: descripción, captura, comentarios, asignación y estado (SIEG: support-tickets/Show.tsx). */
@Component({
  selector: 'app-ticket-detalle',
  imports: [AccionComponent, SelectBusquedaDirective, FormsModule, RouterLink, DatePipe, KeyValuePipe],
  templateUrl: './ticket-detalle.component.html'
})
export class TicketDetalleComponent {
  private servicio = inject(TicketsService);
  protected auth = inject(AuthService);

  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));
  readonly estados = ESTADOS;
  readonly prioridades = PRIORIDADES;
  readonly clasePrioridad = clasePrioridad;
  readonly claseEstado = claseEstadoTicket;
  readonly sinOrden = () => 0;

  d = signal<DetalleTicket | null>(null);
  error = signal('');
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(
    history.state?.mensaje ? { tipo: 'success', texto: history.state.mensaje as string } : null
  );

  comentario = '';
  errorComentario = signal('');
  ocupado = signal(false);

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.detalle(this.id).subscribe({
      next: (d) => this.d.set(d),
      error: (e: HttpErrorResponse) => this.error.set(e.status === 403 ? 'Este ticket no es tuyo.' : e.status === 404 ? 'El ticket no existe.' : erroresDe(e).mensaje)
    });
  }

  actualizar(campo: 'status' | 'assigned_to', valor: string | number | null): void {
    this.ocupado.set(true);
    this.servicio.actualizar(this.id, { [campo]: valor }).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        this.mensaje.set({ tipo: 'danger', texto: erroresDe(e).mensaje });
        this.cargar();
      }
    });
  }

  comentar(): void {
    this.ocupado.set(true);
    this.errorComentario.set('');
    this.servicio.comentar(this.id, this.comentario).subscribe({
      next: () => {
        this.ocupado.set(false);
        this.comentario = '';
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errorComentario.set(campos['comment'] ?? mensaje);
      }
    });
  }
}
