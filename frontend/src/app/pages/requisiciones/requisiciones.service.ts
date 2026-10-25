import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

export interface FilaRequisicion {
  id: number;
  folio: string;
  año: number;
  registro: string | null;
  solicitado: string | null;
  concepto: string | null;
  unidad: string | null;
  estado_id: number;
  estado: string | null;
  total: number;
  suficiencia: boolean | null;
  extemporanea: boolean;
  extemporanea_pendiente: boolean;
  orden: string | null;
  clc: string | null;
  clc_estado: string | null;
}

export interface ListaRequisiciones extends Paginado<FilaRequisicion> {
  estados: { id: number; descripcion: string }[];
  veTodas?: boolean;
}

/** Línea del presupuesto que se puede elegir en un concepto. */
export interface LineaCaptura {
  clave: string;
  unidad_clave: string;
  fuente: string;
  nombre_fuente: string;
  proyecto: string;
  nombre_proyecto: string;
  partida: string;
  nombre_partida: string;
  disponible: number;
}

export interface UnidadCaptura {
  id: number;
  clave: string;
  nombre: string;
}

export interface OpcionesCaptura {
  unidades: UnidadCaptura[];
  lineas: LineaCaptura[];
  unidadDefault: number | null;
  mantenimiento: { id: number; folio: string; vehiculo: string; area: string | null; tipo: string; descripcion: string | null } | null;
  periodoCaptura: { inicio: string; fin: string; abierto: boolean };
}

export interface FilaCaptura {
  fuente: string;
  proyecto: string;
  partida: string;
  cantidad: number | null;
  descripcion: string;
  precio_unitario?: number | null;
  lleva_iva?: boolean;
  /** Sin factura (apoyo): monto del renglón, que se guarda como su precio. */
  monto?: number | null;
}

export interface Edicion {
  cotizacion: boolean;
  unidades: UnidadCaptura[];
  lineas: LineaCaptura[];
  requisicion: {
    id: number;
    folio: string;
    id_cat_egreso_unidad_administrativa: number;
    concepto: string;
    fecha_tramite: string;
    filas: FilaCaptura[];
    sin_factura?: boolean;
    beneficiario?: string | null;
    beneficiario_referencia?: string | null;
    beneficiario_fecha?: string | null;
    incluye_iva?: boolean;
    /** Requisición de mantenimiento vehicular: el taller captura los precios. */
    de_mantenimiento?: boolean;
  };
}

export interface PartidaValidada {
  clave: string;
  fuente: string;
  proyecto: string;
  partida: string;
  nombre_partida: string | null;
  existe: boolean;
  requerido: number;
  vigente: number;
  comprometido: number;
  disponible: number;
  faltante: number;
  suficiente: boolean;
}

export interface DetalleRequisicion {
  requisicion: {
    id: number;
    folio: string;
    año: number;
    registro: string | null;
    solicitado: string | null;
    concepto: string | null;
    departamento: string | null;
    unidad: string | null;
    estado_id: number;
    estado: string | null;
    sub_total: number;
    iva: number;
    total: number;
    suficiencia: boolean | null;
    suficiencia_fecha: string | null;
    /** Sin factura: apoyo a un beneficiario, con su documento de referencia. */
    sin_factura: boolean;
    beneficiario: string | null;
    beneficiario_referencia: string | null;
    beneficiario_fecha: string | null;
    extemporanea: boolean;
    extemporanea_pendiente: boolean;
    extemporanea_autorizada_at: string | null;
    extemporanea_autorizador: string | null;
    cancelada_at: string | null;
    cancelada_por: string | null;
    motivo_cancelacion: string | null;
    detalles: {
      id: number;
      cantidad: number;
      descripcion: string;
      fuente: string;
      proyecto: string;
      partida: string;
      precio_unitario: number | null;
      lleva_iva: boolean;
      sub_total: number | null;
      iva: number | null;
      total: number | null;
    }[];
    bitacora: { id: number; fecha: string | null; estado: string | null; observaciones: string | null }[];
  };
  nombres: { fuentes: Record<string, string>; proyectos: Record<string, string>; partidas: Record<string, string> };
  validacion: { suficiente: boolean; total: number; partidas: PartidaValidada[] };
  periodoCaptura: [string, string];
  orden: {
    id: number;
    folio: string;
    fecha: string | null;
    importe: number;
    observaciones: string | null;
    proveedor_id: number | null;
    proveedor: string | null;
    rfc: string | null;
    /** Registro de la CLC de un apoyo sin factura (folio AP-…), no una compra. */
    sin_factura: boolean;
    factura: string | null;
    xml: {
      uuid: string;
      emisor: string | null;
      rfc_emisor: string | null;
      serie_folio: string | null;
      fecha: string | null;
      total: number;
      isr: number;
      cargado_at: string | null;
      sat_estado: string | null;
      sat_estatus_cancelacion: string | null;
      sat_efos: boolean;
      sat_verificado_at: string | null;
      /** Ya tiene su PDF (representación impresa). */
      pdf: boolean;
    } | null;
    xml_uuid_cancelado: string | null;
    refacturas: { fecha: string | null; usuario: string | null; factura: string; clc_anterior: string | null; clc_nueva: string | null; motivo: string }[];
    clc: string | null;
    clc_fecha: string | null;
    clc_estado: string | null;
    clc_cancelada: boolean;
    clc_motivo_cancelacion: string | null;
    neto: number;
    pago_fecha: string | null;
    pago_forma: string | null;
    pago_referencia: string | null;
    movimientos: { fecha: string | null; accion: string; estado: string; usuario: string | null; relacion: string | null; observaciones: string | null }[];
    almacen_asignada_at: string | null;
    cancelada: boolean;
    cancelada_at: string | null;
    motivo_cancelacion: string | null;
  } | null;
  motivoNoCancelarClc: string | null;
  mantenimiento: { folio: string; vehiculo: string; area: string | null; estado: string } | null;
  modificaciones: { id: number; folio_completo: string; tipo: string; estado: string; importe: number }[];
  puede: Record<
    | 'editar'
    | 'enviar'
    | 'validar'
    | 'imprimir'
    | 'enviarRecursos'
    | 'recibir'
    | 'devolver'
    | 'cotizar'
    | 'suficiencia'
    | 'extemporanea'
    | 'cambiarFecha'
    | 'cancelar'
    | 'eliminar'
    | 'modificacion'
    | 'generarOrden'
    | 'imprimirOrden'
    | 'cambiarProveedor'
    | 'cambiarFechaOrden'
    | 'cargarXml'
    | 'descargarXml'
    | 'cargarPdf'
    | 'verificarSat'
    | 'refacturar'
    | 'cancelarOrden'
    | 'altaProveedor'
    | 'generarClc'
    | 'imprimirClc'
    | 'cancelarClc'
    | 'verAlmacen'
    | 'generarClcSinFactura',
    boolean
  >;
}

export interface LineaGasto {
  clave: string;
  unidad_clave: string;
  unidad: string;
  fuente: string;
  nombre_fuente: string;
  proyecto: string;
  nombre_proyecto: string;
  partida: string;
  nombre_partida: string;
  asignado: number;
  modificado: number;
  vigente: number;
  comprometido: number;
  devengado: number;
  ejercido: number;
  pagado: number;
  disponible: number;
  saldo_corrido: number;
  disponible_mes: number;
}

export interface RespuestaGasto extends Paginado<LineaGasto> {
  totales: Record<string, number>;
  unidades: UnidadCaptura[];
  saldos: {
    mes: string;
    mesAnterior: string | null;
    corrida: { fecha: string; origen: string; usuario: string | null; total: number } | null;
    puedeCorrer: boolean;
  };
}

export interface DetalleGasto {
  requisiciones: {
    id: number;
    folio: string;
    fecha: string | null;
    estado_id: number;
    estado: string | null;
    momento: string | null;
    importe: number;
    suficiencia_fecha: string | null;
    cancelada_at: string | null;
    orden: { folio: string; proveedor: string | null; factura: string | null; clc: string | null; clc_estado: string | null; pago_fecha: string | null; cancelada: boolean } | null;
  }[];
  modificaciones: { id: number; folio: string; tipo: string; estado: string; lado: string; contraparte: string | null; mes: string | null; importe: number; fecha: string | null }[];
}

export interface ProveedorOrden {
  id: number;
  nombre: string;
  rfc: string | null;
  vigencia: string | null;
  vencido: boolean;
}

interface Mensaje {
  message: string;
}

function parametros(filtros: Record<string, string | number | boolean | null | undefined>): HttpParams {
  let params = new HttpParams();
  for (const [k, v] of Object.entries(filtros)) {
    if (v !== null && v !== undefined && v !== '' && v !== false) {
      params = params.set(k, v === true ? '1' : String(v));
    }
  }
  return params;
}

/** Llamadas a /api/requisiciones y /api/recursos-materiales (SIEG: EgresoRequisicionController). */
@Injectable({ providedIn: 'root' })
export class RequisicionesService {
  private http = inject(HttpClient);
  private api = environment.apiUrl;

  lista(bandeja: 'requisiciones' | 'recursos-materiales', filtros: Record<string, string | number>) {
    return this.http.get<ListaRequisiciones>(`${this.api}/${bandeja}`, { params: parametros(filtros) });
  }

  detalle(id: number) {
    return this.http.get<DetalleRequisicion>(`${this.api}/requisiciones/${id}`);
  }

  /** Con mantenimiento: la requisición del taller, a nombre de la secretaría de resguardo del vehículo. */
  opciones(mantenimiento?: number) {
    const params = mantenimiento ? new HttpParams().set('mantenimiento', mantenimiento) : undefined;
    return this.http.get<OpcionesCaptura>(`${this.api}/requisiciones/opciones`, { params });
  }

  edicion(id: number) {
    return this.http.get<Edicion>(`${this.api}/requisiciones/${id}/edicion`);
  }

  guardar(datos: object, id?: number) {
    return id
      ? this.http.put<Mensaje>(`${this.api}/requisiciones/${id}`, datos)
      : this.http.post<Mensaje & { id: number; extemporanea: boolean }>(`${this.api}/requisiciones`, datos);
  }

  eliminar(id: number) {
    return this.http.delete<Mensaje>(`${this.api}/requisiciones/${id}`);
  }

  /** Acciones del trámite: enviar, aprobar, rechazar, recursos-materiales, recibido, devolver-area, suficiencia, autorizar-extemporanea, cancelar, fecha. */
  accion(id: number, accion: string, datos: object = {}) {
    return this.http.post<Mensaje & { suficiente?: boolean }>(`${this.api}/requisiciones/${id}/${accion}`, datos);
  }

  pdf(id: number) {
    return this.http.get(`${this.api}/requisiciones/${id}/pdf`, { responseType: 'blob' });
  }

  // --- Orden de compra (SIEG: OrdenCompraController) ---

  proveedores() {
    return this.http.get<ProveedorOrden[]>(`${this.api}/ordenes-compra/proveedores`);
  }

  /** Alta rápida del proveedor desde la orden: el mismo catálogo /catalogos/proveedores. */
  /** Alta desde la orden de compra: proveedor de compra única (eventual), aparte en el padrón y sin cédula. */
  altaProveedor(nombre: string, rfc: string) {
    return this.http.post<Mensaje & { id: number }>(`${this.api}/catalogos/proveedores`, { nombre, rfc, activo: true, compra_unica: true });
  }

  generarOrden(requisicion: number, datos: { proveedor_id: number | null; fecha: string; observaciones: string }) {
    return this.http.post<Mensaje & { id: number }>(`${this.api}/requisiciones/${requisicion}/orden-compra`, datos);
  }

  /** Acciones de la orden: proveedor, fecha, verificar-sat, refacturar, cancelar. */
  accionOrden(orden: number, accion: string, datos: object = {}) {
    return this.http.post<Mensaje & { aviso?: string | null; vigente?: boolean }>(`${this.api}/ordenes-compra/${orden}/${accion}`, datos);
  }

  cargarXml(orden: number, archivo: File) {
    const datos = new FormData();
    datos.append('xml', archivo);
    return this.http.post<Mensaje & { aviso?: string | null }>(`${this.api}/ordenes-compra/${orden}/xml`, datos);
  }

  pdfClc(orden: number) {
    return this.http.get(`${this.api}/ordenes-compra/${orden}/clc`, { responseType: 'blob' });
  }

  pdfOrden(orden: number) {
    return this.http.get(`${this.api}/ordenes-compra/${orden}/pdf`, { responseType: 'blob' });
  }

  cargarPdfFactura(orden: number, archivo: File) {
    const datos = new FormData();
    datos.append('pdf', archivo);
    return this.http.post<Mensaje>(`${this.api}/ordenes-compra/${orden}/factura-pdf`, datos);
  }

  descargarPdfFactura(orden: number) {
    return this.http.get(`${this.api}/ordenes-compra/${orden}/factura-pdf`, { responseType: 'blob' });
  }

  descargarXml(orden: number) {
    return this.http.get(`${this.api}/ordenes-compra/${orden}/xml`, { responseType: 'blob' });
  }

  gasto(filtros: Record<string, string | number | boolean>) {
    return this.http.get<RespuestaGasto>(`${this.api}/requisiciones/gasto`, { params: parametros(filtros) });
  }

  gastoDetalle(clave: string) {
    return this.http.get<DetalleGasto>(`${this.api}/requisiciones/gasto/detalle`, { params: { clave } });
  }

  exportarGasto(filtros: Record<string, string | number | boolean>) {
    return this.http.get(`${this.api}/requisiciones/gasto/exportar`, { params: parametros(filtros), responseType: 'blob' });
  }
}

/** Color del estado de la requisición: uno distinto por estado (clases .estado-req-N en styles.scss). */
export function claseEstado(estadoId: number): string {
  return estadoId >= 1 && estadoId <= 12 ? `estado-req estado-req-${estadoId}` : 'bg-light-secondary text-secondary';
}

/** Descarga o abre un blob (PDF, Excel) que se pidió con el token. */
export function abrirBlob(blob: Blob, nombre: string, descargar = false): void {
  const url = URL.createObjectURL(blob);
  if (descargar) {
    const a = document.createElement('a');
    a.href = url;
    a.download = nombre;
    a.click();
  } else {
    window.open(url, '_blank');
  }
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}
