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

interface Orden {
  id: number;
  folio: string;
  fecha: string | null;
  importe: number;
  proveedor: string | null;
  rfc: string | null;
  requisicion_id: number;
  requisicion: string | null;
  concepto: string | null;
  unidad: string | null;
  factura: string | null;
  sat_estado: string | null;
  almacen: boolean;
  clc: string | null;
  clc_estado: string | null;
  clc_clave: string | null;
  pagada: boolean;
  cancelada: boolean;
  motivo_cancelacion: string | null;
}

interface RespuestaOrdenes extends Paginado<Orden> {
  totales: { ordenes: number; importe: number };
}

/** Listado de órdenes de compra; las acciones (factura, CLC, cancelación…) siguen en la requisición. */
@Component({
  selector: 'app-ordenes-compra',
  imports: [SelectBusquedaDirective, AccionComponent, FormsModule, RouterLink, DatePipe, PaginacionComponent],
  templateUrl: './ordenes-compra.component.html',
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
  `
})
export class OrdenesCompraComponent {
  private http = inject(HttpClient);
  private requisiciones = inject(RequisicionesService);

  readonly money = money;

  datos = signal<RespuestaOrdenes | null>(null);
  filtros: Record<string, string> = { buscar: '', situacion: '', desde: '', hasta: '' };
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
    this.http.get<RespuestaOrdenes>(`${environment.apiUrl}/ordenes-compra`, { params }).subscribe((d) => this.datos.set(d));
  }

  limpiar(): void {
    this.filtros = { buscar: '', situacion: '', desde: '', hasta: '' };
    this.cargar(1);
  }

  hayFiltros(): boolean {
    return Object.values(this.filtros).some((v) => !!v);
  }

  /** En qué va la orden, de lo más avanzado a lo menos. */
  situacion(o: Orden): { texto: string; clase: string } {
    if (o.cancelada) return { texto: 'Cancelada', clase: 'bg-danger' };
    if (o.pagada) return { texto: 'Pagada', clase: 'bg-success' };
    if (o.clc) return { texto: 'En CLC', clase: 'bg-primary' };
    if (o.factura) return { texto: 'Con factura', clase: 'bg-warning' };
    return { texto: 'Sin factura', clase: 'bg-secondary' };
  }

  pdf(o: Orden): void {
    this.requisiciones.pdfOrden(o.id).subscribe({
      next: (b) => abrirBlob(b, `${o.folio}.pdf`),
      error: () => this.error.set(`No se pudo generar el PDF de ${o.folio}.`)
    });
  }
}
