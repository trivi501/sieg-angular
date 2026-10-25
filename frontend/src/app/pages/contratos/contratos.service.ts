import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

export type EstadoContrato = 'captura' | 'por_autorizar' | 'rechazado' | 'vigente' | 'terminado' | 'cancelado';

export interface FilaContrato {
  id: number;
  folio: string;
  tipo: string;
  numero_contrato: string | null;
  unidad: string | null;
  proveedor: string | null;
  concepto: string;
  fecha_inicio: string;
  fecha_fin: string;
  monto_mensual: number;
  monto_total: number;
  meses: number;
  facturados: number;
  estado: EstadoContrato;
  estado_texto: string;
}

export interface LineaPresupuesto {
  fuente: string;
  nombre_fuente: string | null;
  proyecto: string;
  nombre_proyecto: string | null;
  partida: string;
  nombre_partida: string | null;
  disponible: number;
}

export interface OpcionesContrato {
  unidades: { id: number; clave: string; nombre: string }[];
  proveedores: { id: number; nombre: string; rfc: string | null }[];
  tipos: Record<string, string>;
  unidad: number | null;
  lineas: LineaPresupuesto[];
}

/** Datos del formulario del contrato (alta y edición). */
export interface DatosContrato {
  tipo: string;
  numero_contrato: string;
  id_cat_egreso_unidad_administrativa: number | null;
  proveedor_id: number | null;
  concepto: string;
  fecha_inicio: string;
  fecha_fin: string;
  monto_mensual: number | null;
  monto_total: number | null;
  fuente: string;
  proyecto: string;
  partida: string;
}

export type EstadoMes = 'pendiente' | 'vencida' | 'generada' | 'pagada';

export interface MesContrato {
  periodo: string;
  nombre: string;
  estado: EstadoMes;
  importe: number | null;
  factura: string | null;
  tramite: string | null;
  requisicion: string | null;
  requisicion_id: number | null;
  orden_id: number | null;
  orden: string | null;
  clc: string | null;
  clc_estado: string | null;
  pago: string | null;
  pago_fecha: string | null;
  canceladas: number;
}

export interface DetalleContrato {
  contrato: {
    id: number;
    folio: string;
    tipo: string;
    tipo_texto: string;
    numero_contrato: string | null;
    id_unidad: number;
    unidad: string | null;
    proveedor_id: number;
    proveedor: string | null;
    rfc: string | null;
    impedimento_proveedor: string | null;
    concepto: string;
    fecha_inicio: string;
    fecha_fin: string;
    monto_mensual: number;
    monto_total: number;
    fuente: string;
    proyecto: string;
    partida: string;
    nombre_partida: string | null;
    disponible: number | null;
    estado: EstadoContrato;
    estado_texto: string;
    observaciones: string | null;
    capturo: string | null;
    autorizado_por: string | null;
    autorizado_at: string | null;
  };
  meses: MesContrato[];
  resumen: {
    meses: number;
    generadas: number;
    pagadas: number;
    pendientes: number;
    vencidas: number;
    ejercido: number;
    pagado: number;
    saldo: number;
  };
  siguiente: string | null;
  puede: { editar: boolean; enviar: boolean; autorizar: boolean; cancelar: boolean; terminar: boolean; facturar: boolean };
}

interface Mensaje {
  message: string;
  aviso?: string | null;
  id?: number;
}

/** Llamadas a /api/contratos (ContratoController). */
@Injectable({ providedIn: 'root' })
export class ContratosService {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/contratos`;

  lista(filtros: Record<string, string | number>) {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== '' && v !== null && v !== undefined) params = params.set(k, v);
    }
    return this.http.get<Paginado<FilaContrato> & { estados: Record<EstadoContrato, string> }>(this.api, { params });
  }

  opciones(unidad: number | null) {
    return this.http.get<OpcionesContrato>(`${this.api}/opciones`, { params: unidad ? { unidad } : {} });
  }

  crear(datos: DatosContrato) {
    return this.http.post<Mensaje>(this.api, datos);
  }

  actualizar(id: number, datos: DatosContrato) {
    return this.http.put<Mensaje>(`${this.api}/${id}`, datos);
  }

  detalle(id: number) {
    return this.http.get<DetalleContrato>(`${this.api}/${id}`);
  }

  accion(id: number, accion: 'enviar' | 'autorizar' | 'rechazar' | 'cancelar', datos: object = {}) {
    return this.http.post<Mensaje>(`${this.api}/${id}/${accion}`, datos);
  }

  facturar(id: number, periodo: string, archivo: File) {
    const datos = new FormData();
    datos.append('periodo', periodo);
    datos.append('xml', archivo);
    return this.http.post<Mensaje>(`${this.api}/${id}/mensualidades`, datos);
  }
}

export function claseEstadoContrato(estado: EstadoContrato): string {
  return (
    {
      captura: 'bg-light-secondary text-secondary',
      por_autorizar: 'bg-light-warning text-warning',
      rechazado: 'bg-light-danger text-danger',
      vigente: 'bg-light-success text-success',
      terminado: 'bg-light-primary text-primary',
      cancelado: 'bg-light-dark text-dark'
    } as const
  )[estado];
}

export function claseEstadoMes(estado: EstadoMes): string {
  return (
    {
      pendiente: 'bg-light-secondary text-secondary',
      vencida: 'bg-light-danger text-danger',
      generada: 'bg-light-warning text-warning',
      pagada: 'bg-light-success text-success'
    } as const
  )[estado];
}

/** Opciones encadenadas partida → proyecto → fuente de las líneas de presupuesto de la secretaría. */
export function opcionesPresupuesto(lineas: LineaPresupuesto[], partida: string, proyecto: string) {
  const unicas = (ls: LineaPresupuesto[], campo: 'partida' | 'proyecto', nombre: 'nombre_partida' | 'nombre_proyecto') => {
    const vistos = new Map<string, string | null>();
    for (const l of ls) if (!vistos.has(l[campo])) vistos.set(l[campo], l[nombre]);
    return [...vistos].map(([clave, n]) => ({ clave, nombre: n }));
  };
  return {
    partidas: unicas(lineas, 'partida', 'nombre_partida'),
    proyectos: unicas(
      lineas.filter((l) => l.partida === partida),
      'proyecto',
      'nombre_proyecto'
    ),
    fuentes: lineas
      .filter((l) => l.partida === partida && l.proyecto === proyecto)
      .map((l) => ({ clave: l.fuente, nombre: l.nombre_fuente, disponible: l.disponible }))
  };
}
