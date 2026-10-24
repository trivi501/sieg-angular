import { Component, TemplateRef, inject, signal, viewChild } from '@angular/core';
import { DatePipe, KeyValuePipe } from '@angular/common';
import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { NgbModal, NgbModalRef } from '@ng-bootstrap/ng-bootstrap';

import { environment } from 'src/environments/environment';
import { Paginado, erroresDe } from 'src/app/core/api';
import { PaginacionComponent } from '../admin/paginacion.component';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

interface Mantenimiento {
  id: number;
  folio: string;
  vehiculo: string;
  secretaria: string | null;
  area: string | null;
  fecha_recepcion: string | null;
  kilometraje: number | null;
  tipo: string;
  descripcion: string;
  entrega: string | null;
  recibio: string | null;
  estado: 'en_taller' | 'entregado';
  fecha_entrega: string | null;
  recibe_entrega: string | null;
  observaciones_entrega: string | null;
  requisicion_id: number | null;
  requisicion: string | null;
  requisicion_estado: string | null;
  requisicion_cancelada: boolean;
}

interface Respuesta extends Paginado<Mantenimiento> {
  vehiculos: { id: number; texto: string; secretaria: string | null; area: string | null }[];
  noDisponibles: { texto: string; motivo: string }[];
  tipos: Record<string, string>;
  puedeRequisicion: boolean;
}

/**
 * Mantenimiento vehicular (SIEG: vehiculos/Mantenimientos.tsx): recibir el vehículo en el taller, hacer su
 * requisición a nombre de la secretaría de resguardo y entregarlo al terminar.
 */
@Component({
  selector: 'app-mantenimientos',
  imports: [SelectBusquedaDirective, AccionComponent, FormsModule, RouterLink, DatePipe, KeyValuePipe, PaginacionComponent],
  templateUrl: './mantenimientos.component.html',
  styles: `
    td,
    th {
      font-size: 13px;
    }
  `
})
export class MantenimientosComponent {
  private http = inject(HttpClient);
  private modal = inject(NgbModal);
  private api = `${environment.apiUrl}/vehiculos/mantenimientos`;

  private tplRecibir = viewChild.required<TemplateRef<unknown>>('recibirTpl');
  private tplEntregar = viewChild.required<TemplateRef<unknown>>('entregarTpl');

  readonly hoy = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' });
  readonly sinOrden = () => 0;

  datos = signal<Respuesta | null>(null);
  buscar = '';
  estado = '';
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);

  // Recepción
  recepcion: { vehiculo_id: number | null; fecha_recepcion: string; kilometraje: number | null; tipo: string; descripcion: string; entrega: string } = this.recepcionVacia();
  // Entrega
  entregando = signal<Mantenimiento | null>(null);
  entrega = { fecha_entrega: '', recibe_entrega: '', observaciones_entrega: '' };

  errores = signal<Record<string, string>>({});
  ocupado = signal(false);

  constructor() {
    this.cargar(1);
  }

  private recepcionVacia() {
    return { vehiculo_id: null, fecha_recepcion: this.hoy, kilometraje: null, tipo: 'correctivo', descripcion: '', entrega: '' };
  }

  cargar(page: number): void {
    let params = new HttpParams().set('page', page);
    if (this.buscar) {
      params = params.set('buscar', this.buscar);
    }
    if (this.estado) {
      params = params.set('estado', this.estado);
    }
    this.http.get<Respuesta>(this.api, { params }).subscribe((d) => this.datos.set(d));
  }

  vehiculoElegido() {
    return this.datos()?.vehiculos.find((v) => v.id === this.recepcion.vehiculo_id);
  }

  abrirRecibir(): void {
    this.recepcion = this.recepcionVacia();
    this.errores.set({});
    this.modal.open(this.tplRecibir(), { size: 'lg' });
  }

  abrirEntregar(m: Mantenimiento): void {
    this.entregando.set(m);
    this.entrega = { fecha_entrega: this.hoy, recibe_entrega: '', observaciones_entrega: '' };
    this.errores.set({});
    this.modal.open(this.tplEntregar());
  }

  recibir(ventana: NgbModalRef): void {
    this.enviar(this.http.post<{ message: string }>(this.api, this.recepcion), ventana);
  }

  entregar(ventana: NgbModalRef): void {
    this.enviar(this.http.post<{ message: string }>(`${this.api}/${this.entregando()!.id}/entregar`, this.entrega), ventana);
  }

  private enviar(peticion: ReturnType<HttpClient['post']>, ventana: NgbModalRef): void {
    this.ocupado.set(true);
    this.errores.set({});
    peticion.subscribe({
      next: (r) => {
        this.ocupado.set(false);
        ventana.close();
        this.mensaje.set({ tipo: 'success', texto: (r as { message: string }).message });
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
      }
    });
  }
}
