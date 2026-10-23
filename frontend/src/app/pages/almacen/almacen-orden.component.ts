import { Component, TemplateRef, computed, inject, signal, viewChild } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { NgbModal, NgbModalRef } from '@ng-bootstrap/ng-bootstrap';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { AlmacenService, Concepto, OrdenAlmacen, cantidad } from './almacen.service';
import { AccionComponent } from 'src/app/shared/accion.component';

type Tipo = 'entradas' | 'salidas';

/**
 * Una orden de compra en el almacén (SIEG: almacen/Show.tsx): conceptos con lo pedido, recibido, entregado,
 * por recibir y existencia; registrar entradas (hasta lo que falta por recibir) y salidas (hasta la
 * existencia), y sus formatos.
 */
@Component({
  selector: 'app-almacen-orden',
  imports: [AccionComponent, FormsModule, RouterLink, DatePipe],
  templateUrl: './almacen-orden.component.html',
  styles: `
    td,
    th {
      font-size: 13px;
    }
  `
})
export class AlmacenOrdenComponent {
  private servicio = inject(AlmacenService);
  private modal = inject(NgbModal);

  private tplMovimiento = viewChild.required<TemplateRef<unknown>>('movimientoTpl');

  readonly money = money;
  readonly cantidad = cantidad;
  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));
  readonly hoy = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' });

  d = signal<OrdenAlmacen | null>(null);
  error = signal('');
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string; pdf?: { tipo: 'entrada' | 'salida'; id: number; folio: string } } | null>(null);

  // Registro de entrada o salida
  tipo = signal<Tipo>('entradas');
  fecha = '';
  observaciones = '';
  recibe = '';
  cantidades: Record<number, number | null> = {};
  errores = signal<Record<string, string>>({});
  guardando = signal(false);

  /** Límite de cada concepto: por recibir (entrada) o existencia (salida). */
  limite(c: Concepto): number {
    return this.tipo() === 'entradas' ? c.por_recibir : c.existencia;
  }

  conceptosConLimite = computed(() => (this.d()?.conceptos ?? []).filter((c) => this.limite(c) > 0));

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.orden(this.id).subscribe({
      next: (d) => this.d.set(d),
      error: (e: HttpErrorResponse) => this.error.set(erroresDe(e).mensaje || 'No se pudo cargar la orden de compra.')
    });
  }

  abrir(tipo: Tipo): void {
    this.tipo.set(tipo);
    this.fecha = this.hoy;
    this.observaciones = '';
    this.recibe = '';
    this.cantidades = {};
    this.errores.set({});
    this.modal.open(this.tplMovimiento(), { size: 'lg', scrollable: true });
  }

  /** Llena cada concepto con todo lo que se puede recibir o entregar. */
  todo(): void {
    for (const c of this.conceptosConLimite()) {
      this.cantidades[c.detalle_id] = this.limite(c);
    }
  }

  registrar(ventana: NgbModalRef): void {
    const cantidades: Record<number, number> = {};
    for (const [id, valor] of Object.entries(this.cantidades)) {
      if (valor !== null && valor !== undefined && `${valor}` !== '') {
        cantidades[Number(id)] = Number(valor);
      }
    }

    this.guardando.set(true);
    this.errores.set({});
    this.servicio
      .registrar(this.id, this.tipo(), {
        fecha: this.fecha,
        observaciones: this.observaciones,
        ...(this.tipo() === 'salidas' ? { recibe: this.recibe } : {}),
        cantidades
      })
      .subscribe({
        next: (r) => {
          this.guardando.set(false);
          ventana.close();
          this.mensaje.set({ tipo: 'success', texto: r.message, pdf: { tipo: this.tipo() === 'entradas' ? 'entrada' : 'salida', id: r.id, folio: r.folio } });
          this.cargar();
        },
        error: (e: HttpErrorResponse) => {
          this.guardando.set(false);
          const { campos, mensaje } = erroresDe(e);
          this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
        }
      });
  }

  imprimir(tipo: 'entrada' | 'acta' | 'salida', id: number, folio: string): void {
    const nombre = { entrada: 'Entrada de almacén', acta: 'Acta de entrega', salida: 'Salida de almacén' }[tipo];
    this.servicio.pdf(tipo, id).subscribe({
      next: (b) => abrirBlob(b, `${nombre} ${folio}.pdf`),
      error: () => this.mensaje.set({ tipo: 'danger', texto: `No se pudo generar el formato ${folio}.` })
    });
  }
}
