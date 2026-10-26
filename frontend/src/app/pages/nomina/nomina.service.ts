import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';
import { LineaPresupuesto } from '../contratos/contratos.service';

export type TipoNomina = 'nomina' | 'seguridad_social' | 'finiquito' | 'laudo';
export type EstadoNomina = 'captura' | 'por_validar' | 'rechazado' | 'validado' | 'cancelado';

export interface FilaNomina {
  id: number;
  folio: string;
  tipo: TipoNomina;
  tipo_texto: string;
  periodo: string | null;
  fecha_pago: string | null;
  concepto: string;
  beneficiario: string;
  referencia: string | null;
  renglones: number;
  total: number;
  estado: EstadoNomina;
  estado_texto: string;
}

export interface OpcionTipo {
  clave: TipoNomina;
  nombre: string;
  beneficiario: string | null;
  individual: boolean;
}

export interface OpcionesNomina {
  tipos: OpcionTipo[];
  unidades: { id: number; clave: string; nombre: string }[];
  lineas: LineaPresupuesto[];
}

/** Datos del encabezado (alta y edición). */
export interface DatosNomina {
  tipo: TipoNomina;
  periodo: string;
  fecha_pago: string;
  concepto: string;
  beneficiario: string;
  beneficiario_numero: string;
  referencia: string;
}

export interface RenglonNomina {
  id: number;
  fila: number | null;
  unidad_clave: string;
  unidad: string | null;
  fuente: string;
  proyecto: string;
  partida: string;
  concepto: string;
  importe: number;
}

export interface DatosRenglon {
  id_cat_egreso_unidad_administrativa: number | null;
  fuente: string;
  proyecto: string;
  partida: string;
  concepto: string;
  importe: number | null;
}

export interface DetalleNomina {
  nomina: {
    id: number;
    folio: string;
    tipo: TipoNomina;
    tipo_texto: string;
    individual: boolean;
    periodo: string | null;
    fecha_pago: string | null;
    concepto: string;
    beneficiario: string;
    beneficiario_numero: string | null;
    a_favor_de: string;
    referencia: string | null;
    archivo: string | null;
    estado: EstadoNomina;
    estado_texto: string;
    observaciones: string | null;
    capturo: string | null;
    validado_por: string | null;
    validado_at: string | null;
    total: number;
  };
  renglones: RenglonNomina[];
  suficiencia: {
    unidad_clave: string;
    fuente: string;
    proyecto: string;
    partida: string;
    nombre_partida: string | null;
    requerido: number;
    disponible: number;
    suficiente: boolean;
  }[];
  clc: {
    orden_id: number;
    registro: string;
    requisicion_id: number;
    requisicion: string | null;
    unidad: string | null;
    importe: number;
    clc: string | null;
    clc_estado: string | null;
    pago_fecha: string | null;
  }[];
  puede: { editar: boolean; enviar: boolean; validar: boolean; cancelar: boolean };
}

interface Mensaje {
  message: string;
  id?: number;
}

/** Llamadas a /api/nominas (NominaController). */
@Injectable({ providedIn: 'root' })
export class NominaService {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/nominas`;

  lista(filtros: Record<string, string | number>) {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== '' && v !== null && v !== undefined) params = params.set(k, v);
    }
    return this.http.get<Paginado<FilaNomina> & { estados: Record<EstadoNomina, string>; tipos: Record<TipoNomina, string> }>(this.api, {
      params
    });
  }

  opciones(unidad: number | null = null) {
    return this.http.get<OpcionesNomina>(`${this.api}/opciones`, { params: unidad ? { unidad } : {} });
  }

  crear(datos: DatosNomina) {
    return this.http.post<Mensaje>(this.api, datos);
  }

  actualizar(id: number, datos: DatosNomina) {
    return this.http.put<Mensaje>(`${this.api}/${id}`, datos);
  }

  detalle(id: number) {
    return this.http.get<DetalleNomina>(`${this.api}/${id}`);
  }

  importar(id: number, archivo: File) {
    const datos = new FormData();
    datos.append('archivo', archivo);
    return this.http.post<Mensaje>(`${this.api}/${id}/importar`, datos);
  }

  agregarRenglon(id: number, datos: DatosRenglon) {
    return this.http.post<Mensaje>(`${this.api}/${id}/renglones`, datos);
  }

  quitarRenglon(id: number, renglon: number) {
    return this.http.delete<Mensaje>(`${this.api}/${id}/renglones/${renglon}`);
  }

  accion(id: number, accion: 'enviar' | 'validar' | 'rechazar' | 'cancelar', datos: object = {}) {
    return this.http.post<Mensaje>(`${this.api}/${id}/${accion}`, datos);
  }

  plantilla() {
    return this.http.get(`${this.api}/plantilla`, { responseType: 'blob' });
  }

  archivo(id: number) {
    return this.http.get(`${this.api}/${id}/archivo`, { responseType: 'blob' });
  }
}

export function claseEstadoNomina(estado: EstadoNomina): string {
  return (
    {
      captura: 'bg-light-secondary text-secondary',
      por_validar: 'bg-light-warning text-warning',
      rechazado: 'bg-light-danger text-danger',
      validado: 'bg-light-success text-success',
      cancelado: 'bg-light-dark text-dark'
    } as const
  )[estado];
}
