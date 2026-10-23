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

interface Factura {
  id: number;
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
  requisicion_id: number;
  requisicion: string | null;
  requisicion_estado: string | null;
  unidad: string | null;
  orden: string;
  orden_cancelada: boolean;
  clc: string | null;
  clc_estado: string | null;
  clc_clave: string | null;
  xml: boolean;
}

interface RespuestaFacturas extends Paginado<Factura> {
  totales: { facturas: number; total: number };
}

/**
 * Facturas (CFDI) cargadas a las órdenes de compra, también las canceladas con su orden: emisor, folio,
 * fecha, monto, validación en el SAT y en qué va su trámite (SIEG: pages/facturas/Index.tsx).
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

  readonly money = money;

  datos = signal<RespuestaFacturas | null>(null);
  filtros: Record<string, string> = { buscar: '', sat: '', vigencia: '', desde: '', hasta: '' };
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
    this.filtros = { buscar: '', sat: '', vigencia: '', desde: '', hasta: '' };
    this.cargar(1);
  }

  hayFiltros(): boolean {
    return Object.values(this.filtros).some((v) => !!v);
  }

  claseSat(estado: string | null): string {
    return estado === 'Vigente' ? 'bg-light-success text-success' : estado ? 'bg-light-danger text-danger' : 'bg-light-secondary text-secondary';
  }

  descargarXml(f: Factura): void {
    this.requisiciones.descargarXml(f.id).subscribe({
      next: (b) => abrirBlob(b, `${f.orden}.xml`, true),
      error: () => this.error.set(`No se encontró el XML de la factura de ${f.orden}.`)
    });
  }
}
