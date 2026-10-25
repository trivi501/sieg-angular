import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';
import { EstadoTramite } from '../servicios/servicios.service';

/** Una carga del Excel del proveedor (una hoja): de ella salen las bitácoras, una por secretaría. */
export interface LoteCombustible {
  id: number;
  periodo: string;
  archivo: string;
  hoja: string | null;
  fecha: string | null;
  capturo: string | null;
  tickets: number;
  litros: number;
  importe: number;
  bitacoras: number;
  estados: Partial<Record<EstadoTramite, number>>;
  facturas_esperadas: number;
  facturas_cargadas: number;
}

/** Lectura del Excel antes de cargarlo. */
export interface PreviaCombustible {
  hojas: string[];
  hoja: string;
  periodo: string;
  tickets: number;
  litros: number;
  importe: number;
  facturas: number;
  proveedores: string[];
  vehiculos_padron: number;
  secretarias: {
    clave: string;
    nombre: string | null;
    nombre_excel: string | null;
    unidad_id: number | null;
    tickets: number;
    litros: number;
    importe: number;
    facturas: number;
  }[];
  /** Facturas del Excel que ya se subieron (pasan solas a su bitácora) y subidas que este Excel no incluye. */
  facturas_con_xml: number;
  por_asignar_fuera: number;
  errores: string[];
  total_errores: number;
  avisos: string[];
  total_avisos: number;
}

/** XML subido antes del Excel: espera a que un Excel diga de qué secretaría es. */
export interface FacturaPorAsignar {
  id: number;
  uuid: string;
  folio: string;
  emisor: string | null;
  rfc_emisor: string;
  fecha: string | null;
  total: number;
  sat_estado: string | null;
  subida: string | null;
}

export interface BitacoraLote {
  id: number;
  folio: string;
  unidad: string | null;
  estado: EstadoTramite;
  estado_texto: string;
  tickets: number;
  litros: number;
  importe: number;
  facturas_esperadas: number;
  facturas_cargadas: number;
  requisicion: string | null;
}

export interface DetalleLote {
  lote: Omit<LoteCombustible, 'bitacoras' | 'estados' | 'facturas_esperadas' | 'facturas_cargadas'>;
  bitacoras: BitacoraLote[];
  puede: { subir: boolean; eliminar: boolean };
}

/** Resumen de la bitácora en el detalle del trámite (tipo combustible). */
export interface ResumenCombustible {
  lote_id: number | null;
  periodo: string | null;
  archivo: string | null;
  tickets: number;
  litros: number;
  importe: number;
  facturas: {
    folio: string;
    fecha_factura: string | null;
    tickets: number;
    litros: number;
    importe: number;
    factura_id: number | null;
    total_xml: number | null;
    diferencia: number | null;
  }[];
}

export interface TicketCombustible {
  id: number;
  fila: number;
  fecha_ticket: string | null;
  ticket: string | null;
  vale: string | null;
  numero_economico: string | null;
  vehiculo_id: number | null;
  placas: string | null;
  departamento: string | null;
  conductor: string | null;
  producto: string | null;
  litros: number;
  precio: number | null;
  importe: number;
  factura_folio: string;
  uuid: string | null;
}

export type Agrupacion = 'secretaria' | 'vehiculo' | 'mes' | 'producto';

export interface ReporteCombustible {
  filas: { grupo: string; unidad: string | null; tickets: number; litros: number; importe: number; precio_promedio: number | null }[];
  totales: { tickets: number; litros: number; importe: number };
  agrupaciones: Record<Agrupacion, string>;
  filtros: { desde: string; hasta: string; agrupar: Agrupacion; unidad: number | null; descripcion: string };
  unidades: { id: number; clave: string; nombre: string }[];
}

interface Mensaje {
  message: string;
  aviso?: string | null;
  id?: number;
}

/** Llamadas a /api/combustible (CombustibleController); las de cada bitácora van por ServiciosService (tipo combustible). */
@Injectable({ providedIn: 'root' })
export class CombustibleService {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/combustible`;

  lotes(page: number) {
    return this.http.get<Paginado<LoteCombustible>>(`${this.api}/lotes`, { params: { page } });
  }

  previa(archivo: File, hoja: string | null) {
    const datos = new FormData();
    datos.append('archivo', archivo);
    if (hoja) datos.append('hoja', hoja);
    return this.http.post<PreviaCombustible>(`${this.api}/lotes/previa`, datos);
  }

  importar(archivo: File, hoja: string | null, periodo: string) {
    const datos = new FormData();
    datos.append('archivo', archivo);
    if (hoja) datos.append('hoja', hoja);
    datos.append('periodo', periodo);
    return this.http.post<Mensaje>(`${this.api}/lotes`, datos);
  }

  lote(id: number) {
    return this.http.get<DetalleLote>(`${this.api}/lotes/${id}`);
  }

  /** Si ya hay Excel con ese folio, va a la bitácora de su secretaría (bitacora); si no, queda por asignar (bitacora null). */
  subirFactura(archivo: File) {
    const datos = new FormData();
    datos.append('xml', archivo);
    return this.http.post<Mensaje & { bitacora?: string | null }>(`${this.api}/facturas`, datos);
  }

  porAsignar() {
    return this.http.get<FacturaPorAsignar[]>(`${this.api}/facturas/por-asignar`);
  }

  quitarPorAsignar(id: number) {
    return this.http.delete<Mensaje>(`${this.api}/facturas/por-asignar/${id}`);
  }

  eliminarLote(id: number) {
    return this.http.delete<Mensaje>(`${this.api}/lotes/${id}`);
  }

  tickets(bitacora: number) {
    return this.http.get<{ tickets: TicketCombustible[] }>(`${this.api}/${bitacora}/tickets`);
  }

  bitacora(bitacora: number, formato: 'excel' | 'pdf') {
    return this.http.get(`${this.api}/${bitacora}/bitacora/${formato}`, { responseType: 'blob' });
  }

  reporte(filtros: Record<string, string | number | null>) {
    return this.http.get<ReporteCombustible>(`${this.api}/reporte`, { params: this.params(filtros) });
  }

  reporteExcel(filtros: Record<string, string | number | null>) {
    return this.http.get(`${this.api}/reporte/excel`, { params: this.params(filtros), responseType: 'blob' });
  }

  private params(filtros: Record<string, string | number | null>): HttpParams {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== '' && v !== null && v !== undefined) params = params.set(k, v);
    }
    return params;
  }
}

export function litros(n: number | null | undefined): string {
  return new Intl.NumberFormat('es-MX', { minimumFractionDigits: 3, maximumFractionDigits: 3 }).format(Number(n ?? 0));
}
