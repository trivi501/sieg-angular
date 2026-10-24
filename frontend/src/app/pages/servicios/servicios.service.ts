import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

/** servicio (/servicios, TS-…) o fondo revolvente (/fondos, FR-…). */
export type TipoTramite = 'servicio' | 'fondo';
export const RUTA_TIPO: Record<TipoTramite, string> = { servicio: 'servicios', fondo: 'fondos' };

export type EstadoTramite = 'captura' | 'por_validar' | 'rechazado' | 'validado' | 'cancelado';

export interface FilaTramite {
  id: number;
  folio: string;
  fecha: string | null;
  unidad: string | null;
  concepto: string;
  proveedor: string | null;
  rfc: string | null;
  facturas: number;
  importe: number;
  estado: EstadoTramite;
  estado_texto: string;
  requisicion_id: number | null;
  requisicion: string | null;
}

export interface ListaTramites extends Paginado<FilaTramite> {
  estados: Record<EstadoTramite, string>;
}

export interface ConceptoFactura {
  /** Fondo revolvente: cada artículo con su partida / proyecto / fuente. */
  partida?: string | null;
  proyecto?: string | null;
  fuente?: string | null;
  nombre_partida?: string | null;
  iva?: number;
  isr?: number;
  cantidad: number | null;
  unidad: string | null;
  clave: string | null;
  descripcion: string | null;
  valor_unitario: number;
  descuento: number;
  importe: number;
}

export interface FacturaTramite {
  id: number;
  uuid: string;
  serie_folio: string | null;
  fecha: string | null;
  emisor: string | null;
  rfc_emisor: string;
  subtotal: number;
  descuento: number;
  iva: number;
  isr: number;
  total: number;
  importe: number;
  conceptos: ConceptoFactura[];
  /** Fondo: montos de la factura por partida (lo de sus artículos). */
  por_partida: { fuente: string; proyecto: string; partida: string; nombre_partida: string | null; base: number; iva: number; isr: number; importe: number }[];
  sat_estado: string | null;
  fuente: string | null;
  proyecto: string | null;
  partida: string | null;
  nombre_partida: string | null;
  concepto: string | null;
  clasificada: boolean;
}

export interface LineaTramite {
  fuente: string;
  nombre_fuente: string | null;
  proyecto: string;
  nombre_proyecto: string | null;
  partida: string;
  nombre_partida: string | null;
  disponible: number;
}

export interface DetalleTramite {
  tramite: {
    id: number;
    tipo: TipoTramite;
    folio: string;
    fecha: string | null;
    id_unidad: number;
    unidad: string | null;
    unidad_clave: string;
    concepto: string;
    estado: EstadoTramite;
    estado_texto: string;
    observaciones: string | null;
    capturo: string | null;
    enviado_at: string | null;
    validado_at: string | null;
    validado_por: string | null;
    proveedor_rfc: string | null;
    proveedor_nombre: string | null;
    proveedor: { id: number; nombre: string; activo: boolean } | null;
    impedimento_proveedor: string | null;
    trabajador_nombre: string | null;
    trabajador_numero: string | null;
    trabajador_puesto: string | null;
    limite_factura: number | null;
    importe: number;
    neto: number;
    isr: number;
    requisicion_id: number | null;
    requisicion: string | null;
    orden: { id: number; folio: string; clc: string | null; clc_estado: string | null } | null;
  };
  facturas: FacturaTramite[];
  lineas: LineaTramite[];
  puede: { editar: boolean; enviar: boolean; validar: boolean; cancelar: boolean; documentos: boolean };
}

interface Mensaje {
  message: string;
  aviso?: string | null;
  id?: number;
}

/** Llamadas a /api/servicios (TramiteServicioController). */
@Injectable({ providedIn: 'root' })
export class ServiciosService {
  private http = inject(HttpClient);
  private api = (tipo: TipoTramite) => `${environment.apiUrl}/${RUTA_TIPO[tipo]}`;

  lista(tipo: TipoTramite, filtros: Record<string, string | number>) {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== '' && v !== null && v !== undefined) params = params.set(k, v);
    }
    return this.http.get<ListaTramites>(this.api(tipo), { params });
  }

  crear(tipo: TipoTramite, datos: { id_cat_egreso_unidad_administrativa: number | null; concepto: string; trabajador_nombre?: string; trabajador_numero?: string; trabajador_puesto?: string }) {
    return this.http.post<Mensaje>(this.api(tipo), datos);
  }

  detalle(tipo: TipoTramite, id: number) {
    return this.http.get<DetalleTramite>(`${this.api(tipo)}/${id}`);
  }

  actualizar(tipo: TipoTramite, id: number, datos: { concepto: string; trabajador_nombre?: string | null; trabajador_numero?: string | null; trabajador_puesto?: string | null }) {
    return this.http.put<Mensaje>(`${this.api(tipo)}/${id}`, datos);
  }

  subirFactura(tipo: TipoTramite, id: number, archivo: File) {
    const datos = new FormData();
    datos.append('xml', archivo);
    return this.http.post<Mensaje>(`${this.api(tipo)}/${id}/facturas`, datos);
  }

  /** Servicio: partida de la factura. Fondo: articulos[i] con la partida de cada artículo. */
  clasificar(tipo: TipoTramite, id: number, factura: number, datos: object) {
    return this.http.put<Mensaje>(`${this.api(tipo)}/${id}/facturas/${factura}`, datos);
  }

  quitarFactura(tipo: TipoTramite, id: number, factura: number) {
    return this.http.delete<Mensaje>(`${this.api(tipo)}/${id}/facturas/${factura}`);
  }

  descargarXml(tipo: TipoTramite, id: number, factura: number) {
    return this.http.get(`${this.api(tipo)}/${id}/facturas/${factura}/xml`, { responseType: 'blob' });
  }

  accion(tipo: TipoTramite, id: number, accion: 'enviar' | 'validar' | 'rechazar' | 'cancelar', datos: object = {}) {
    return this.http.post<Mensaje>(`${this.api(tipo)}/${id}/${accion}`, datos);
  }
}

/** Color del estado del trámite. */
export function claseEstadoTramite(estado: EstadoTramite): string {
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
