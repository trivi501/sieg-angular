import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

/** Columnas de los meses en la tabla `presupuesto 2024`, en orden. */
export const MESES: { key: string; nombre: string }[] = [
  { key: 'ENERO', nombre: 'Enero' },
  { key: 'FEBRERO', nombre: 'Febrero' },
  { key: 'MARZO', nombre: 'Marzo' },
  { key: 'ABRIL', nombre: 'Abril' },
  { key: 'MAYO', nombre: 'Mayo' },
  { key: 'JUNIO', nombre: 'Junio' },
  { key: 'JULIO', nombre: 'Julio' },
  { key: 'AGOSTO', nombre: 'Agosto' },
  { key: 'SEP', nombre: 'Septiembre' },
  { key: 'OCTUBRE', nombre: 'Octubre' },
  { key: 'NOV', nombre: 'Noviembre' },
  { key: 'DIC', nombre: 'Diciembre' }
];

export interface LineaPresupuesto {
  id_presupuesto: number;
  CLAVE: string;
  UNIDAD_ADMINISTRATIVA: string;
  CLAVE3: string;
  FUENTE_DE_FINANCIAMIENTO: string;
  CLAVE6: string;
  PROYECTO: string;
  PARTIDA: string;
  NOMBRE_PARTIDA: string;
  capitulo: string | null;
  TIPO_DE_GASTO: string | null;
  IMPORTE_TOTAL: number;
  [mes: string]: unknown;
}

export interface FilaPresupuesto extends LineaPresupuesto {
  diferencia: number;
  modificado: number;
  vigente: number;
  vigente_mes: number;
  comprometido: number;
  devengado: number;
  pagado: number;
  disponible: number;
}

export interface Totales {
  asignado: number;
  modificado: number;
  vigente: number;
  comprometido: number;
  devengado: number;
  pagado: number;
  disponible: number;
}

export interface RespuestaPresupuesto extends Paginado<FilaPresupuesto> {
  mesActual: string;
  totalDescuadradas: number;
  totales: Totales;
  corridaMes: { fecha: string | null; origen: string; lineas: number; total_corrido: number } | null;
  puede: { crear: boolean; editar: boolean; importar: boolean; borrar: boolean; correrSaldos: boolean };
}

export interface SaldoMes {
  mes: number;
  nombre: string;
  saldo_corrido: number;
  asignado: number;
  modificado: number;
  comprometido: number;
  disponible: number;
}

export interface DetallePresupuesto {
  linea: LineaPresupuesto;
  diferencia: number;
  saldos: Partial<Totales & { en_tramite: number }>;
  ejercicio: number;
  mesActual: number;
  meses: SaldoMes[];
}

export interface CatalogosPresupuesto {
  unidades: { clave: string; nombre: string }[];
  fuentes: { clave: string; nombre: string }[];
  proyectos: { clave: string; nombre: string }[];
  partidas: { clave: string; nombre: string; tipo_gasto: string | number }[];
}

interface Mensaje {
  message: string;
}

/** Llamadas a /api/presupuesto (SIEG: PresupuestoController). */
@Injectable({ providedIn: 'root' })
export class PresupuestoService {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/presupuesto`;

  lista(page: number, filtros: Record<string, string>) {
    let params = new HttpParams().set('page', page);
    for (const [k, v] of Object.entries(filtros)) {
      if (v) {
        params = params.set(k, v);
      }
    }
    return this.http.get<RespuestaPresupuesto>(this.api, { params });
  }

  detalle(id: number) {
    return this.http.get<DetallePresupuesto>(`${this.api}/${id}`);
  }

  catalogos() {
    return this.http.get<CatalogosPresupuesto>(`${this.api}/catalogos`);
  }

  guardar(datos: Record<string, unknown>, id?: number) {
    return id ? this.http.put<Mensaje>(`${this.api}/${id}`, datos) : this.http.post<Mensaje & { id: number }>(this.api, datos);
  }

  importar(archivo: File) {
    const datos = new FormData();
    datos.append('file', archivo);
    return this.http.post<Mensaje & { aviso: boolean }>(`${this.api}/import`, datos);
  }

  correrSaldos() {
    return this.http.post<Mensaje>(`${this.api}/correr-saldos`, {});
  }

  eliminarTodo() {
    return this.http.delete<Mensaje>(this.api);
  }
}
