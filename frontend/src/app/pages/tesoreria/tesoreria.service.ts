import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

/** Cuenta bancaria del Ayuntamiento de la que salen los pagos. */
export interface CuentaTesoreria {
  id: number;
  etiqueta: string;
  fuente: string | null;
  banco: string | null;
}

/** CLC en la mesa de control (por recibir o por pagar). */
export interface ClcMesa {
  id: number;
  clc: string;
  clc_fecha: string | null;
  estado: string;
  estado_texto: string;
  orden: string;
  requisicion: string | null;
  requisicion_id: number | null;
  unidad: string | null;
  concepto: string | null;
  beneficiario_clave: string;
  beneficiario: string;
  proveedor_id: number | null;
  rfc: string | null;
  cuenta_destino: string | null;
  importe: number;
  isr: number;
  neto: number;
  fuentes: string[];
  sat_estado: string | null;
  sat_verificado_at: string | null;
}

export interface GrupoBeneficiario {
  clave: string;
  beneficiario: string;
  rfc: string | null;
  proveedor_id: number | null;
  cuenta_destino: string | null;
  clc: number;
  importe: number;
  isr: number;
  neto: number;
  fuentes: string[];
  ordenes: ClcMesa[];
}

export interface Mesa {
  por_recibir: ClcMesa[];
  beneficiarios: GrupoBeneficiario[];
  totales: { por_recibir: number; por_pagar: number; neto: number };
  cuentas: CuentaTesoreria[];
  fuentes: string[];
  puede: { recibir: boolean; pagar: boolean };
}

export type EstadoPago = 'pagado' | 'entregado' | 'conciliado' | 'cancelado';

export interface Pago {
  id: number;
  numero: string;
  fecha: string | null;
  beneficiario: string;
  forma: 'transferencia' | 'cheque';
  forma_texto: string;
  cheque_numero: string | null;
  cuenta_destino: string | null;
  cuenta_id: number;
  cuenta: string | null;
  fuente: string | null;
  importe: number;
  estado: EstadoPago;
  estado_texto: string;
  clc: number;
}

export interface Movimiento {
  id: number;
  cuenta_id: number;
  cuenta: string | null;
  fecha: string | null;
  tipo: 'cargo' | 'abono';
  importe: number;
  referencia: string | null;
  clave_rastreo: string | null;
  concepto: string | null;
  origen: string;
  pago_id: number | null;
  pago: string | null;
}

export interface DetallePago {
  pago: Pago & {
    observaciones: string | null;
    capturo: string | null;
    entregado_at: string | null;
    entregado_a: string | null;
    conciliado_at: string | null;
    motivo_cancelacion: string | null;
  };
  ordenes: {
    id: number;
    clc: string;
    orden: string;
    requisicion: string | null;
    requisicion_id: number | null;
    importe: number;
    sat_estado: string | null;
  }[];
  movimiento: Movimiento | null;
  candidatos: Movimiento[];
  puede: { cancelar: boolean; entregar: boolean; conciliar: boolean; desconciliar: boolean };
}

export interface FilaPosicion {
  id: number;
  banco: string;
  cuenta: string;
  fuente: string;
  saldo_inicial: number;
  abonos: number;
  cargos: number;
  saldo_banco: number;
  en_transito: number;
  pagos_en_transito: number;
  disponible: number;
}

export interface Posicion {
  corte: string;
  cuentas: FilaPosicion[];
  por_banco: { clave: string; cuentas: number; saldo_banco: number; en_transito: number; disponible: number }[];
  por_fuente: { clave: string; cuentas: number; saldo_banco: number; en_transito: number; disponible: number }[];
  totales: Record<'saldo_inicial' | 'abonos' | 'cargos' | 'saldo_banco' | 'en_transito' | 'disponible', number>;
}

export interface Cheques {
  cheques: Pago[];
  resumen: { estado: EstadoPago; texto: string; cantidad: number; importe: number }[];
  cuentas: CuentaTesoreria[];
}

export interface Mensaje {
  message: string;
}

type Filtros = Record<string, string | number | null | undefined>;

/** Llamadas a /api/tesoreria (TesoreriaController). */
@Injectable({ providedIn: 'root' })
export class TesoreriaService {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/tesoreria`;

  mesa(filtros: Filtros = {}) {
    return this.http.get<Mesa>(`${this.api}/mesa`, { params: this.params(filtros) });
  }

  cuentas() {
    return this.http.get<CuentaTesoreria[]>(`${this.api}/cuentas`);
  }

  verificarSat(orden: number) {
    return this.http.post<Mensaje & { vigente: boolean }>(`${this.api}/clc/${orden}/verificar-sat`, {});
  }

  pagar(datos: {
    ordenes: number[];
    cuenta_id: number | null;
    pago_forma: string;
    pago_fecha: string;
    pago_referencia?: string;
    observaciones?: string;
  }) {
    return this.http.post<Mensaje & { pagos: { id: number; numero: string }[] }>(`${this.api}/pagos`, datos);
  }

  pagos(filtros: Filtros) {
    return this.http.get<Paginado<Pago> & { estados: Record<EstadoPago, string>; cuentas: CuentaTesoreria[] }>(`${this.api}/pagos`, {
      params: this.params(filtros)
    });
  }

  pago(id: number) {
    return this.http.get<DetallePago>(`${this.api}/pagos/${id}`);
  }

  accionPago(id: number, accion: 'cancelar' | 'entregar' | 'conciliar' | 'desconciliar', datos: object = {}) {
    return this.http.post<Mensaje>(`${this.api}/pagos/${id}/${accion}`, datos);
  }

  conciliarAutomatico(cuentaId: number | null) {
    return this.http.post<Mensaje & { conciliados: string[]; con_diferencia: string[] }>(`${this.api}/pagos/conciliar-automatico`, {
      cuenta_id: cuentaId
    });
  }

  movimientos(filtros: Filtros) {
    return this.http.get<Paginado<Movimiento> & { totales: { abonos: number; cargos: number }; cuentas: CuentaTesoreria[] }>(
      `${this.api}/movimientos`,
      {
        params: this.params(filtros)
      }
    );
  }

  guardarMovimiento(datos: object) {
    return this.http.post<Mensaje>(`${this.api}/movimientos`, datos);
  }

  eliminarMovimiento(id: number) {
    return this.http.delete<Mensaje>(`${this.api}/movimientos/${id}`);
  }

  importarMovimientos(cuentaId: number, archivo: File) {
    const datos = new FormData();
    datos.append('cuenta_id', String(cuentaId));
    datos.append('archivo', archivo);
    return this.http.post<Mensaje>(`${this.api}/movimientos/importar`, datos);
  }

  posicion(corte: string) {
    return this.http.get<Posicion>(`${this.api}/posicion`, { params: this.params({ corte }) });
  }

  cheques(filtros: Filtros) {
    return this.http.get<Cheques>(`${this.api}/cheques`, { params: this.params(filtros) });
  }

  /** Excel: posicion/excel, movimientos/excel, cheques/excel o movimientos/plantilla. */
  excel(ruta: string, filtros: Filtros = {}) {
    return this.http.get(`${this.api}/${ruta}`, { params: this.params(filtros), responseType: 'blob' });
  }

  private params(filtros: Filtros): HttpParams {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filtros)) {
      if (v !== '' && v !== null && v !== undefined) params = params.set(k, v);
    }
    return params;
  }
}

/** Color del estado del pago. */
export function claseEstadoPago(estado: EstadoPago): string {
  return (
    {
      pagado: 'bg-light-warning text-warning',
      entregado: 'bg-light-info text-info',
      conciliado: 'bg-light-success text-success',
      cancelado: 'bg-light-dark text-dark'
    } as const
  )[estado];
}
