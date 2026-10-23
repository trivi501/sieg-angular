import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

export interface Captura {
  abierta: boolean;
  siempre: boolean;
  inicio: string;
  ultimoDia: string;
  calendario: boolean;
  proximaApertura: string;
  diasCierre: number;
}

export interface Modificacion {
  id: number;
  folio_completo: string;
  año: number;
  tipo: 'traspaso' | 'reduccion' | 'ampliacion';
  estado: 'pendiente' | 'autorizada' | 'rechazada';
  importe: number;
  justificacion: string;
  origen_clave: string | null;
  origen_fuente: string | null;
  origen_proyecto: string | null;
  origen_partida: string | null;
  origen_descripcion: string | null;
  origen_mes: number | null;
  origen_disponible?: number | null;
  origen_disponible_mes?: number | null;
  destino_clave: string | null;
  destino_fuente: string | null;
  destino_proyecto: string | null;
  destino_partida: string | null;
  destino_descripcion: string | null;
  destino_mes: number | null;
  destino_disponible?: number | null;
  destino_disponible_mes?: number | null;
  observaciones_resolucion: string | null;
  origen_disponible_antes: number | null;
  destino_vigente_antes: number | null;
  resuelto_at: string | null;
  created_at: string | null;
  solicitante: string | null;
  autorizador: string | null;
}

export interface ListaModificaciones extends Paginado<Modificacion> {
  tipos: Record<string, string>;
  meses: Record<string, string>;
  captura: Captura;
}

export interface LineaModificacion {
  clave: string;
  unidad_clave: string;
  unidad: string;
  fuente: string;
  nombre_fuente: string;
  proyecto: string;
  nombre_proyecto: string;
  partida: string;
  nombre_partida: string;
  vigente: number;
  disponible: number;
}

export interface OpcionesModificacion {
  lineas: LineaModificacion[];
  meses: Record<string, string>;
  mesActual: number;
  disponiblesMes: Record<string, Record<string, number>>;
  tipos: Record<string, string>;
  requisicion: { id: number; folio: string } | null;
  destinoSugerido: string | null;
  importeSugerido: number | null;
  captura: Captura;
}

export interface SaldoLinea {
  clave: string;
  unidad: string;
  fuente: string;
  proyecto: string;
  partida: string;
  nombre_partida: string;
  asignado: number;
  modificado: number;
  vigente: number;
  comprometido: number;
  disponible: number;
}

export interface DetalleModificacion {
  modificacion: Modificacion & { requisicion: { id: number; folio: string } | null };
  tipos: Record<string, string>;
  meses: Record<string, string>;
  origen: SaldoLinea | null;
  destino: SaldoLinea | null;
  puedeResolver: boolean;
}

interface Mensaje {
  message: string;
}

/** Llamadas a /api/modificaciones-presupuestales (SIEG: ModificacionPresupuestalController). */
@Injectable({ providedIn: 'root' })
export class ModificacionesService {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/modificaciones-presupuestales`;

  lista(filtros: Record<string, string | number>) {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== '' && v !== null && v !== undefined) {
        params = params.set(k, v);
      }
    }
    return this.http.get<ListaModificaciones>(this.api, { params });
  }

  opciones(requisicion?: number) {
    const params = requisicion ? new HttpParams().set('requisicion', requisicion) : undefined;
    return this.http.get<OpcionesModificacion>(`${this.api}/opciones`, { params });
  }

  guardar(datos: object) {
    return this.http.post<Mensaje & { id: number }>(this.api, datos);
  }

  detalle(id: number) {
    return this.http.get<DetalleModificacion>(`${this.api}/${id}`);
  }

  resolver(id: number, accion: 'autorizar' | 'rechazar', observaciones: string) {
    return this.http.post<Mensaje>(`${this.api}/${id}/${accion}`, { observaciones });
  }

  pdf(id: number) {
    return this.http.get(`${this.api}/${id}/pdf`, { responseType: 'blob' });
  }
}

export function claseEstadoModificacion(estado: string): string {
  return estado === 'autorizada' ? 'bg-light-success text-success' : estado === 'rechazada' ? 'bg-light-danger text-danger' : 'bg-light-warning text-warning';
}
