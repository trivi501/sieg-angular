import { Component, TemplateRef, computed, inject, signal, viewChild } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { NgbModal } from '@ng-bootstrap/ng-bootstrap';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { PresupuestoService } from '../presupuesto/presupuesto.service';
import { PaginacionComponent } from '../admin/paginacion.component';
import { DetalleGasto, LineaGasto, RequisicionesService, RespuestaGasto, abrirBlob, claseEstado } from './requisiciones.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { AccionComponent } from 'src/app/shared/accion.component';

/**
 * "Ejercicio y control del gasto" (SIEG: requisiciones/Gasto.tsx): saldos por línea de las unidades
 * del usuario (Admin y Control Presupuestal: todas), con detalle por partida, Excel y corrida de saldos.
 */
@Component({
  selector: 'app-gasto',
  imports: [AccionComponent, SelectBusquedaDirective, FormsModule, RouterLink, PaginacionComponent, DatePipe],
  templateUrl: './gasto.component.html',
  styles: `
    .tabla-gasto td,
    .tabla-gasto th {
      white-space: nowrap;
      font-size: 12.5px;
    }
    .tabla-gasto tbody tr {
      cursor: pointer;
    }
    .texto-largo {
      max-width: 220px;
      overflow: hidden;
      text-overflow: ellipsis;
    }
  `
})
export class GastoComponent {
  private servicio = inject(RequisicionesService);
  private presupuesto = inject(PresupuestoService);
  private modal = inject(NgbModal);

  private tplDetalle = viewChild.required<TemplateRef<unknown>>('detalleTpl');

  readonly money = money;
  readonly claseEstado = claseEstado;

  datos = signal<RespuestaGasto | null>(null);
  unidad = '';
  buscar = '';
  conMovimiento = false;
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);
  ocupado = signal(false);

  linea = signal<LineaGasto | null>(null);
  detalle = signal<DetalleGasto | null>(null);

  readonly columnas = [
    { campo: 'asignado', etiqueta: 'Aprobado' },
    { campo: 'modificado', etiqueta: 'Modificado' },
    { campo: 'vigente', etiqueta: 'Vigente' },
    { campo: 'comprometido', etiqueta: 'Comprometido' },
    { campo: 'devengado', etiqueta: 'Devengado' },
    { campo: 'ejercido', etiqueta: 'Ejercido' },
    { campo: 'pagado', etiqueta: 'Pagado' },
    { campo: 'disponible', etiqueta: 'Disponible' },
    { campo: 'saldo_corrido', etiqueta: 'Saldo corrido' },
    { campo: 'disponible_mes', etiqueta: 'Disponible del mes' }
  ] as const;

  mes = computed(() => this.datos()?.saldos.mes ?? '');

  constructor() {
    this.cargar(1);
  }

  private filtros() {
    return { unidad: this.unidad, buscar: this.buscar, con_movimiento: this.conMovimiento };
  }

  cargar(page: number): void {
    this.servicio.gasto({ ...this.filtros(), page }).subscribe((d) => this.datos.set(d));
  }

  valor(l: LineaGasto | Record<string, number>, campo: string): number {
    return Number((l as unknown as Record<string, number>)[campo] ?? 0);
  }

  exportar(): void {
    this.ocupado.set(true);
    this.servicio.exportarGasto(this.filtros()).subscribe({
      next: (blob) => {
        this.ocupado.set(false);
        abrirBlob(blob, 'ejercicio_y_control_del_gasto.xlsx', true);
      },
      error: () => {
        this.ocupado.set(false);
        this.mensaje.set({ tipo: 'danger', texto: 'No se pudo generar el Excel.' });
      }
    });
  }

  correrSaldos(): void {
    if (!confirm(`¿Correr los saldos de ${this.mes()}? Lo disponible de ${this.datos()?.saldos.mesAnterior ?? 'el mes anterior'} se suma a este mes y queda congelado. Solo se corre una vez por mes.`)) {
      return;
    }
    this.ocupado.set(true);
    this.presupuesto.correrSaldos().subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        this.mensaje.set({ tipo: 'danger', texto: erroresDe(e).mensaje });
      }
    });
  }

  verDetalle(l: LineaGasto): void {
    this.linea.set(l);
    this.detalle.set(null);
    this.modal.open(this.tplDetalle(), { size: 'xl', scrollable: true });
    this.servicio.gastoDetalle(l.clave).subscribe((d) => this.detalle.set(d));
  }
}
