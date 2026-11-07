import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { PaginacionComponent } from '../admin/paginacion.component';
import { RequisicionesService, abrirBlob } from '../requisiciones/requisiciones.service';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { RUTA_TIPO, ServiciosService, TipoTramite } from '../servicios/servicios.service';

/** Tipo de trámite de la factura (columna y filtro). */
type TipoFactura = 'orden' | 'servicio' | 'fondo' | 'viatico' | 'combustible' | 'contrato' | 'por_asignar';

interface Factura {
  clave: string;
  /** orden: factura de una orden de compra; tramite: de un trámite; por_asignar: combustible sin bitácora. */
  origen: 'orden' | 'tramite' | 'por_asignar';
  id: number;
  tipo: TipoFactura;
  tipo_texto: string;
  tramite_id: number | null;
  tramite: string | null;
  contrato_id: number | null;
  uuid: string;
  cancelada: boolean;
  emisor: string | null;
  rfc: string | null;
  folio: string | null;
  fecha: string | null;
  cargada: string | null;
  total: number;
  isr: number;
  sat_estado: string | null;
  sat_estatus_cancelacion: string | null;
  sat_efos: boolean;
  sat_verificado_at: string | null;
  requisicion_id: number | null;
  requisicion: string | null;
  requisicion_estado: string | null;
  unidad: string | null;
  orden_id: number | null;
  orden: string | null;
  orden_cancelada: boolean;
  clc: string | null;
  clc_estado: string | null;
  clc_clave: string | null;
  xml: boolean;
}

interface RespuestaFacturas extends Paginado<Factura> {
  totales: { facturas: number; total: number };
  tipos: Record<TipoFactura, string>;
}

/** Ruta y prefijo de la API de cada tipo de trámite. */
const TRAMITES: Partial<Record<TipoFactura, TipoTramite>> = { servicio: 'servicio', fondo: 'fondo', viatico: 'viatico', combustible: 'combustible' };

/**
 * Todas las facturas (CFDI) cargadas, con su tipo de trámite: órdenes de compra (también las canceladas con su
 * orden), trámites de servicios, fondos revolventes, bitácoras de combustible, mensualidades de contrato y las de
 * combustible por asignar. Emisor, folio, fecha, monto, validación en el SAT y en qué va su trámite
 * (SIEG: pages/facturas/Index.tsx, solo las de las órdenes).
 */
@Component({
  selector: 'app-facturas',
  imports: [SelectBusquedaDirective, AccionComponent, FormsModule, RouterLink, DatePipe, PaginacionComponent],
  templateUrl: './facturas.component.html',
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
    .uuid {
      font-size: 11px;
      word-break: break-all;
    }
  `
})
export class FacturasComponent {
  private http = inject(HttpClient);
  private requisiciones = inject(RequisicionesService);
  private servicios = inject(ServiciosService);

  readonly money = money;

  datos = signal<RespuestaFacturas | null>(null);
  filtros: Record<string, string> = { buscar: '', tipo: '', sat: '', vigencia: '', desde: '', hasta: '' };
  error = signal('');

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    let params = new HttpParams().set('page', page);
    for (const [k, v] of Object.entries(this.filtros)) {
      if (v) {
        params = params.set(k, v);
      }
    }
    this.http.get<RespuestaFacturas>(`${environment.apiUrl}/facturas`, { params }).subscribe((d) => this.datos.set(d));
  }

  limpiar(): void {
    this.filtros = { buscar: '', tipo: '', sat: '', vigencia: '', desde: '', hasta: '' };
    this.cargar(1);
  }

  hayFiltros(): boolean {
    return Object.values(this.filtros).some((v) => !!v);
  }

  claseSat(estado: string | null): string {
    return estado === 'Vigente' ? 'bg-light-success text-success' : estado ? 'bg-light-danger text-danger' : 'bg-light-secondary text-secondary';
  }

  /** Tipos para el filtro. */
  tipos(): [string, string][] {
    return Object.entries(this.datos()?.tipos ?? {});
  }

  claseTipo(tipo: TipoFactura): string {
    return (
      {
        orden: 'bg-light-primary text-primary',
        servicio: 'bg-light-info text-info',
        fondo: 'bg-light-warning text-warning',
        viatico: 'bg-light-danger text-danger',
        combustible: 'bg-light-success text-success',
        contrato: 'bg-light-secondary text-secondary',
        por_asignar: 'bg-light-dark text-dark'
      } as const
    )[tipo];
  }

  /** Enlace al trámite de la factura (el contrato, en las mensualidades). */
  enlaceTramite(f: Factura): (string | number)[] | null {
    const tipo = TRAMITES[f.tipo];
    if (tipo && f.tramite_id) return ['/' + RUTA_TIPO[tipo], f.tramite_id];
    if (f.tipo === 'contrato' && f.contrato_id) return ['/contratos', f.contrato_id];
    return null;
  }

  /** Con XML descargable: las de las órdenes y las de servicios, fondos y combustible. */
  puedeDescargar(f: Factura): boolean {
    return f.xml && (f.origen === 'orden' ? !!f.orden_id : !!TRAMITES[f.tipo] && !!f.tramite_id);
  }

  descargarXml(f: Factura): void {
    const tipo = TRAMITES[f.tipo];
    const descarga = f.origen === 'orden' ? this.requisiciones.descargarXml(f.orden_id!) : this.servicios.descargarXml(tipo!, f.tramite_id!, f.id);
    descarga.subscribe({
      next: (b) => abrirBlob(b, `${f.uuid}.xml`, true),
      error: () => this.error.set(`No se encontró el XML de la factura ${f.folio ?? f.uuid}.`)
    });
  }
}
