import { Component, TemplateRef, inject, signal, viewChild } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { NgbModal, NgbModalRef } from '@ng-bootstrap/ng-bootstrap';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { PaginacionComponent } from '../admin/paginacion.component';
import { PresupuestoService, RespuestaPresupuesto } from './presupuesto.service';
import { AccionComponent } from 'src/app/shared/accion.component';

type Mensaje = { tipo: 'success' | 'danger' | 'warning'; texto: string };

/** Presupuesto de egresos con sus momentos del gasto (SIEG: pages/presupuesto/index.tsx). */
@Component({
  selector: 'app-presupuesto-lista',
  imports: [AccionComponent, FormsModule, RouterLink, PaginacionComponent, DatePipe],
  templateUrl: './presupuesto-lista.component.html',
  styles: `
    .tabla-presupuesto td,
    .tabla-presupuesto th {
      white-space: nowrap;
      font-size: 12.5px;
    }
    .tabla-presupuesto .filtro {
      min-width: 90px;
      font-size: 12px;
      padding: 2px 6px;
    }
    .tabla-presupuesto .texto-largo {
      max-width: 220px;
      overflow: hidden;
      text-overflow: ellipsis;
    }
  `
})
export class PresupuestoListaComponent {
  private servicio = inject(PresupuestoService);
  private modal = inject(NgbModal);

  private tplImportar = viewChild.required<TemplateRef<unknown>>('importarTpl');

  readonly money = money;

  /** Columnas filtrables (mismos nombres que en SIEG y en la tabla). */
  readonly columnasFiltro = [
    { key: 'CLAVE', etiqueta: 'Clave' },
    { key: 'UNIDAD_ADMINISTRATIVA', etiqueta: 'Unidad Administrativa' },
    { key: 'CLAVE6', etiqueta: 'Clave proyecto' },
    { key: 'PROYECTO', etiqueta: 'Proyecto' },
    { key: 'PARTIDA', etiqueta: 'Partida' },
    { key: 'capitulo', etiqueta: 'Capítulo' },
    { key: 'NOMBRE_PARTIDA', etiqueta: 'Nombre Partida' },
    { key: 'TIPO_DE_GASTO', etiqueta: 'Tipo Gasto' }
  ];

  datos = signal<RespuestaPresupuesto | null>(null);
  filtros: Record<string, string> = {};
  descuadradas = false;
  mensaje = signal<Mensaje | null>(null);
  ocupado = signal(false);

  // Importación
  archivo: File | null = null;
  errorImportacion = signal('');

  constructor() {
    // Al volver del alta llega filtrado por la partida recién creada.
    const q = inject(ActivatedRoute).snapshot.queryParamMap;
    for (const c of this.columnasFiltro) {
      const v = q.get(c.key);
      if (v) {
        this.filtros[c.key] = v;
      }
    }
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.lista(page, { ...this.filtros, descuadradas: this.descuadradas ? '1' : '' }).subscribe((d) => this.datos.set(d));
  }

  limpiarFiltros(): void {
    this.filtros = {};
    this.descuadradas = false;
    this.cargar(1);
  }

  hayFiltros(): boolean {
    return this.descuadradas || Object.values(this.filtros).some((v) => !!v);
  }

  correrSaldos(): void {
    if (!confirm(`¿Correr los saldos de ${this.datos()?.mesActual}? Lo disponible del mes anterior se suma a este mes y queda congelado. Solo se corre una vez por mes.`)) {
      return;
    }
    this.accion(this.servicio.correrSaldos());
  }

  eliminarTodo(): void {
    if (!confirm('¿Eliminar TODO el presupuesto? Se borran todas las líneas (quedan en la bitácora). Las modificaciones se conservan por clave de línea.')) {
      return;
    }
    if (prompt('Escribe ELIMINAR para confirmar.') !== 'ELIMINAR') {
      return;
    }
    this.accion(this.servicio.eliminarTodo());
  }

  private accion(peticion: ReturnType<PresupuestoService['correrSaldos']>): void {
    this.ocupado.set(true);
    peticion.subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        this.mensaje.set({ tipo: 'danger', texto: erroresDe(e).mensaje || Object.values(erroresDe(e).campos)[0] });
      }
    });
  }

  abrirImportar(): void {
    this.archivo = null;
    this.errorImportacion.set('');
    this.modal.open(this.tplImportar(), { size: 'lg' });
  }

  elegirArchivo(evento: Event): void {
    this.archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
  }

  importar(ventana: NgbModalRef): void {
    if (!this.archivo) {
      return;
    }
    this.ocupado.set(true);
    this.errorImportacion.set('');
    this.servicio.importar(this.archivo).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        ventana.close();
        this.mensaje.set({ tipo: r.aviso ? 'warning' : 'success', texto: r.message });
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errorImportacion.set(campos['file'] ?? mensaje);
      }
    });
  }
}
