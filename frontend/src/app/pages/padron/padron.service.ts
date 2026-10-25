import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

export type EstadoPadron =
  | 'sin_registro'
  | 'en_revision'
  | 'vigente'
  | 'por_vencer'
  | 'vencido'
  | 'rechazado'
  | 'suspendido'
  | 'compra_unica';

export interface FilaPadron {
  id: number;
  nombre: string;
  rfc: string | null;
  giro: string | null;
  compra_unica: boolean;
  activo: boolean;
  estado: EstadoPadron;
  estado_texto: string;
  vigencia: string | null;
  /** Días para que venza la cédula (negativo: ya venció). */
  dias: number | null;
  cedula: string | null;
}

export interface Alertas {
  conteo: { estado: EstadoPadron; texto: string; cantidad: number }[];
  vencidas: FilaPadron[];
  por_vencer: FilaPadron[];
  opiniones_vencidas: FilaPadron[];
  dias_alerta: number;
}

export interface DatosPadron {
  nombre: string;
  rfc: string;
  tipo_persona: string | null;
  representante_legal: string;
  giro: string;
  regimen_fiscal: string;
  correo: string;
  telefono: string;
  domicilio: string;
  clabe: string;
  compra_unica: boolean;
}

export interface DocumentoPadron {
  id: number;
  tipo: string;
  tipo_texto: string;
  nombre_archivo: string;
  fecha_documento: string | null;
  vigente_hasta: string | null;
  vigente: boolean;
  sentido: 'positiva' | 'negativa' | null;
  observaciones: string | null;
  subido: string | null;
}

export interface FichaPadron {
  proveedor: FilaPadron & {
    correo: string | null;
    telefono: string | null;
    domicilio: string | null;
    clabe: string | null;
    tipo_persona: 'fisica' | 'moral' | null;
    representante_legal: string | null;
    regimen_fiscal: string | null;
    observaciones: string | null;
    impedimento: string | null;
  };
  requisitos: { tipo: string; nombre: string; obligatorio: boolean; cumplido: boolean }[];
  documentos: DocumentoPadron[];
  cedulas: { id: number; numero: string; fecha_emision: string; vigencia: string; emitio: string | null; observaciones: string | null }[];
  faltantes: string[];
  tipos_documento: Record<string, string>;
  puede: {
    editar: boolean;
    documentos: boolean;
    emitir: boolean;
    rechazar: boolean;
    suspender: boolean;
    reactivar: boolean;
    cedula_pdf: boolean;
  };
}

interface Mensaje {
  message: string;
  id?: number;
}

/** Llamadas a /api/padron (PadronController). */
@Injectable({ providedIn: 'root' })
export class PadronService {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/padron`;

  lista(filtros: Record<string, string | number>) {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== '' && v !== null && v !== undefined) params = params.set(k, v);
    }
    return this.http.get<Paginado<FilaPadron> & { estados: Record<EstadoPadron, string> }>(this.api, { params });
  }

  alertas() {
    return this.http.get<Alertas>(`${this.api}/alertas`);
  }

  crear(datos: DatosPadron) {
    return this.http.post<Mensaje>(this.api, datos);
  }

  actualizar(id: number, datos: DatosPadron) {
    return this.http.put<Mensaje>(`${this.api}/${id}`, datos);
  }

  ficha(id: number) {
    return this.http.get<FichaPadron>(`${this.api}/${id}`);
  }

  subirDocumento(
    id: number,
    datos: { tipo: string; fecha_documento: string; vigente_hasta?: string; sentido?: string; observaciones?: string },
    archivo: File
  ) {
    const form = new FormData();
    for (const [k, v] of Object.entries(datos)) if (v) form.append(k, v);
    form.append('archivo', archivo);
    return this.http.post<Mensaje>(`${this.api}/${id}/documentos`, form);
  }

  descargarDocumento(id: number, documento: number) {
    return this.http.get(`${this.api}/${id}/documentos/${documento}`, { responseType: 'blob' });
  }

  quitarDocumento(id: number, documento: number) {
    return this.http.delete<Mensaje>(`${this.api}/${id}/documentos/${documento}`);
  }

  emitirCedula(id: number, observaciones: string) {
    return this.http.post<Mensaje>(`${this.api}/${id}/cedula`, { observaciones });
  }

  cedulaPdf(id: number) {
    return this.http.get(`${this.api}/${id}/cedula/pdf`, { responseType: 'blob' });
  }

  estado(id: number, accion: 'rechazar' | 'suspender' | 'reactivar', motivo: string) {
    return this.http.post<Mensaje>(`${this.api}/${id}/${accion}`, { motivo });
  }
}

export function claseEstadoPadron(estado: EstadoPadron): string {
  return (
    {
      sin_registro: 'bg-light-secondary text-secondary',
      en_revision: 'bg-light-info text-info',
      vigente: 'bg-light-success text-success',
      por_vencer: 'bg-light-warning text-warning',
      vencido: 'bg-light-danger text-danger',
      rechazado: 'bg-light-danger text-danger',
      suspendido: 'bg-light-dark text-dark',
      compra_unica: 'bg-light-primary text-primary'
    } as const
  )[estado];
}

export function datosVacios(): DatosPadron {
  return {
    nombre: '',
    rfc: '',
    tipo_persona: null,
    representante_legal: '',
    giro: '',
    regimen_fiscal: '',
    correo: '',
    telefono: '',
    domicilio: '',
    clabe: '',
    compra_unica: false
  };
}
