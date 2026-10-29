import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpContext, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';
import { SIN_LOADER } from 'src/app/core/loading.service';
import { SEGUNDO_PLANO } from 'src/app/core/auth/auth.interceptor';

export interface Ticket {
  id: number;
  title: string;
  priority: 'baja' | 'media' | 'alta' | 'urgente';
  status: 'abierto' | 'en_proceso' | 'resuelto' | 'cerrado';
  user: string | null;
  user_id: number;
  assigned_to: number | null;
  assigned: string | null;
  image: string | null;
  comments_count: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface DetalleTicket {
  ticket: Ticket & { description: string; url: string | null; comments: { id: number; user: string | null; comment: string; created_at: string | null }[] };
  users: { id: number; name: string }[];
  canAssign: boolean;
  canChangeStatus: boolean;
}

export interface Notificacion {
  id: string;
  message: string;
  ticket_id: number | null;
  created_at: string;
}

export const PRIORIDADES: Record<string, string> = { baja: 'Baja', media: 'Media', alta: 'Alta', urgente: 'Urgente' };
export const ESTADOS: Record<string, string> = { abierto: 'Abierto', en_proceso: 'En proceso', resuelto: 'Resuelto', cerrado: 'Cerrado' };

export function clasePrioridad(p: string): string {
  return { baja: 'bg-light-secondary text-secondary', media: 'bg-light-info text-info', alta: 'bg-light-warning text-warning', urgente: 'bg-danger text-white' }[p] ?? '';
}

export function claseEstadoTicket(s: string): string {
  return { abierto: 'bg-light-primary text-primary', en_proceso: 'bg-light-warning text-warning', resuelto: 'bg-light-success text-success', cerrado: 'bg-light-dark text-dark' }[s] ?? '';
}

/** Llamadas a /api/tickets y /api/notifications (SIEG: SupportTicketController). */
@Injectable({ providedIn: 'root' })
export class TicketsService {
  private http = inject(HttpClient);
  private api = environment.apiUrl;

  lista(filtros: Record<string, string | number>) {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== '') {
        params = params.set(k, v);
      }
    }
    return this.http.get<Paginado<Ticket>>(`${this.api}/tickets`, { params });
  }

  detalle(id: number) {
    return this.http.get<DetalleTicket>(`${this.api}/tickets/${id}`);
  }

  crear(datos: FormData) {
    return this.http.post<{ message: string; id: number }>(`${this.api}/tickets`, datos);
  }

  actualizar(id: number, datos: object) {
    return this.http.put<{ message: string }>(`${this.api}/tickets/${id}`, datos);
  }

  comentar(id: number, comment: string) {
    return this.http.post<{ message: string }>(`${this.api}/tickets/${id}/comment`, { comment });
  }

  notificaciones() {
    // Se consulta cada minuto en segundo plano: sin loader y sin contar como actividad (cierre por inactividad).
    return this.http.get<{ unread: Notificacion[]; count: number }>(`${this.api}/notifications`, {
      context: new HttpContext().set(SIN_LOADER, true).set(SEGUNDO_PLANO, true)
    });
  }

  marcarLeida(id: string) {
    return this.http.put<{ success: boolean }>(`${this.api}/notifications/${id}/read`, {});
  }
}
