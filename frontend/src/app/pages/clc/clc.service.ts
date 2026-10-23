import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';

import { environment } from 'src/environments/environment';

export interface Clc {
  id: number;
  clc: string;
  clc_fecha: string | null;
  estado: string;
  estado_texto: string;
  orden: string;
  importe: number;
  isr: number;
  neto: number;
  proveedor: string | null;
  requisicion_id: number;
  requisicion: string | null;
  concepto: string | null;
  unidad: string | null;
  relacion: string | null;
  relacion_id: number | null;
  pago_fecha: string | null;
  pago_forma: string | null;
  transferencia: string | null;
  cancelacion: string | null;
  sat_estado: string | null;
  sat_estatus_cancelacion: string | null;
  sat_efos: boolean;
  sat_verificado_at: string | null;
  devolucion: string | null;
  seguimiento: boolean;
}

export interface AccionClc {
  etiqueta: string;
  permiso: string;
  envio: string | null;
  relacion: boolean;
  recibe: boolean;
  observaciones: boolean;
  pago: boolean;
  a: string;
}

export interface Relacion {
  id: number;
  folio: string;
  origen: string;
  destino: string;
  clcs: number;
  usuario: string | null;
  fecha: string | null;
}

export interface TramiteClc {
  clcs: Clc[];
  veTodo: boolean;
  seguimientoTodas: boolean;
  verRelaciones: boolean;
  relaciones: Relacion[];
  estados: Record<string, string>;
  acciones: Record<string, AccionClc>;
  formasPago: Record<string, string>;
}

/** Llamadas a /api/clc (SIEG: ClcController y TramiteClc). */
@Injectable({ providedIn: 'root' })
export class ClcService {
  private http = inject(HttpClient);
  private api = environment.apiUrl;

  tramite() {
    return this.http.get<TramiteClc>(`${this.api}/clc`);
  }

  accion(datos: { accion: string; ordenes: number[]; observaciones?: string; pago_fecha?: string; pago_forma?: string; pago_referencia?: string }) {
    return this.http.post<{ message: string; relacion: { id: number; folio: string } | null }>(`${this.api}/clc/accion`, datos);
  }

  relacionPdf(id: number) {
    return this.http.get(`${this.api}/clc/relaciones/${id}/pdf`, { responseType: 'blob' });
  }

  clcPdf(orden: number) {
    return this.http.get(`${this.api}/ordenes-compra/${orden}/clc`, { responseType: 'blob' });
  }
}

/** Grupo de una bandeja: las CLC en ciertos estados y las acciones que se aplican a las seleccionadas. */
export interface Grupo {
  titulo: string;
  estados: string[];
  acciones: string[];
}

export interface Bandeja {
  clave: string;
  titulo: string;
  permisos: string[];
  grupos: Grupo[];
}

/** Bandejas por área, en el orden del trámite (SIEG: pages/clc/Index.tsx). */
export const BANDEJAS: Bandeja[] = [
  {
    clave: 'area',
    titulo: 'Mi área',
    permisos: ['requisiciones-clc'],
    grupos: [{ titulo: 'CLC generadas — por enviar a Recursos Materiales', estados: ['generada'], acciones: ['enviar_rm'] }]
  },
  {
    clave: 'rm',
    titulo: 'Recursos Materiales',
    permisos: ['clc-recursos-materiales'],
    grupos: [
      { titulo: 'Por recibir', estados: ['enviada_rm'], acciones: ['recibir_rm'] },
      { titulo: 'En Recursos Materiales — por enviar a Control Presupuestal', estados: ['recibida_rm', 'devuelta'], acciones: ['enviar_cp'] }
    ]
  },
  {
    clave: 'cp',
    titulo: 'Control Presupuestal',
    permisos: ['clc-control-presupuestal'],
    grupos: [
      { titulo: 'Por recibir de Recursos Materiales', estados: ['enviada_cp'], acciones: ['recibir_cp'] },
      { titulo: 'En revisión — enviar a Sindicatura o devolver', estados: ['recibida_cp'], acciones: ['enviar_sindicatura', 'devolver'] },
      { titulo: 'Por recibir de Sindicatura (firmadas)', estados: ['enviada_cp_firmada'], acciones: ['recibir_cp_firmada'] },
      { titulo: 'Firmadas — por enviar a Egresos', estados: ['recibida_cp_firmada'], acciones: ['enviar_egresos'] }
    ]
  },
  {
    clave: 'sindicatura',
    titulo: 'Sindicatura',
    permisos: ['clc-sindicatura'],
    grupos: [
      { titulo: 'Por recibir de Control Presupuestal', estados: ['enviada_sindicatura'], acciones: ['recibir_sindicatura'] },
      { titulo: 'En Sindicatura — por enviar a Control Presupuestal', estados: ['recibida_sindicatura'], acciones: ['enviar_cp_firmada'] }
    ]
  },
  {
    clave: 'egresos',
    titulo: 'Egresos',
    permisos: ['clc-egresos', 'ordenes-compra-pagar'],
    grupos: [
      { titulo: 'Por recibir de Control Presupuestal', estados: ['enviada_egresos'], acciones: ['recibir_egresos'] },
      { titulo: 'Por pagar', estados: ['recibida_egresos'], acciones: ['pagar'] }
    ]
  }
];

/** Color del estado de la CLC. */
export function claseEstadoClc(estado: string): string {
  if (estado === 'pagada') {
    return 'bg-light-success text-success';
  }
  if (estado === 'devuelta' || estado === 'cancelada') {
    return 'bg-light-danger text-danger';
  }
  return estado.startsWith('enviada') ? 'bg-light-warning text-warning' : 'bg-light-primary text-primary';
}
