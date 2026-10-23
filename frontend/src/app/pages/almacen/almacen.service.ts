import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

/** Lo que el almacén lleva de una orden de compra (sumas de sus conceptos). */
export interface ResumenOrden {
  id: number;
  orden: string;
  requisicion_id: number;
  requisicion: string | null;
  unidad: string | null;
  proveedor: string | null;
  asignada: string | null;
  pedido: number;
  recibido: number;
  entregado: number;
  por_recibir: number;
  existencia: number;
}

export interface Concepto {
  detalle_id: number;
  descripcion: string;
  pedido: number;
  precio_unitario: number;
  recibido: number;
  entregado: number;
  por_recibir: number;
  existencia: number;
}

export interface Movimiento {
  id: number;
  folio: string;
  fecha: string | null;
  usuario: string | null;
  recibe?: string | null;
  observaciones: string | null;
  renglones: number;
  cantidad: number;
}

export interface OrdenAlmacen {
  orden: ResumenOrden & { fecha: string | null; importe: number; factura: string | null; cancelada: boolean };
  conceptos: Concepto[];
  entradas: Movimiento[];
  salidas: Movimiento[];
  puede: { entrada: boolean; salida: boolean };
}

export interface FilaInventario extends Concepto {
  orden_id: number;
  orden: string;
  requisicion: string | null;
  unidad: string | null;
  proveedor: string | null;
  valor: number;
}

interface Mensaje {
  message: string;
}

function parametros(filtros: Record<string, string | number | boolean>): HttpParams {
  let params = new HttpParams();
  for (const [k, v] of Object.entries(filtros)) {
    if (v !== '' && v !== false && v !== null && v !== undefined) {
      params = params.set(k, v === true ? '1' : String(v));
    }
  }
  return params;
}

/** Llamadas a /api/almacen (SIEG: AlmacenController y App\Services\Almacen). */
@Injectable({ providedIn: 'root' })
export class AlmacenService {
  private http = inject(HttpClient);
  private api = environment.apiUrl;

  ordenes(filtros: Record<string, string | number>) {
    return this.http.get<Paginado<ResumenOrden>>(`${this.api}/almacen`, { params: parametros(filtros) });
  }

  orden(id: number) {
    return this.http.get<OrdenAlmacen>(`${this.api}/almacen/${id}`);
  }

  inventario(filtros: Record<string, string | number | boolean>) {
    return this.http.get<Paginado<FilaInventario> & { totales: { existencia: number; valor: number } }>(`${this.api}/almacen/inventario`, {
      params: parametros(filtros)
    });
  }

  asignar(orden: number) {
    return this.http.post<Mensaje>(`${this.api}/ordenes-compra/${orden}/almacen`, {});
  }

  registrar(orden: number, tipo: 'entradas' | 'salidas', datos: { fecha: string; observaciones: string; recibe?: string; cantidades: Record<number, number> }) {
    return this.http.post<Mensaje & { id: number; folio: string }>(`${this.api}/almacen/${orden}/${tipo}`, datos);
  }

  /** Formatos: entrada de almacén, acta de entrega (de una entrada) y salida de almacén. */
  pdf(tipo: 'entrada' | 'acta' | 'salida', id: number) {
    const ruta = tipo === 'salida' ? `salidas/${id}/pdf` : tipo === 'acta' ? `entradas/${id}/acta` : `entradas/${id}/pdf`;
    return this.http.get(`${this.api}/almacen/${ruta}`, { responseType: 'blob' });
  }
}

/** Cantidades sin ceros de más: 3, 2.5, 0.25. */
export function cantidad(n: number | null | undefined): string {
  return new Intl.NumberFormat('es-MX', { maximumFractionDigits: 2 }).format(Number(n ?? 0));
}
