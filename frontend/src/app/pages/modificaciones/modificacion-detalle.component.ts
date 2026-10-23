import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { NgbModal } from '@ng-bootstrap/ng-bootstrap';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { DetalleModificacion, ModificacionesService, SaldoLinea, claseEstadoModificacion } from './modificaciones.service';
import { ResolverModificacionComponent } from './resolver.component';
import { AccionComponent } from 'src/app/shared/accion.component';

/** Una modificación: líneas con sus saldos actuales, formato para firma y resolución (SIEG: Show.tsx). */
@Component({
  selector: 'app-modificacion-detalle',
  imports: [AccionComponent, RouterLink, DatePipe],
  templateUrl: './modificacion-detalle.component.html'
})
export class ModificacionDetalleComponent {
  private servicio = inject(ModificacionesService);
  private modal = inject(NgbModal);

  readonly money = money;
  readonly claseEstado = claseEstadoModificacion;
  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));

  d = signal<DetalleModificacion | null>(null);
  error = signal('');
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(
    history.state?.mensaje ? { tipo: 'success', texto: history.state.mensaje as string } : null
  );

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.detalle(this.id).subscribe({
      next: (d) => this.d.set(d),
      error: (e: HttpErrorResponse) =>
        this.error.set(e.status === 404 ? 'La modificación no existe.' : e.status === 403 ? 'Esta modificación no pertenece a tu secretaría.' : erroresDe(e).mensaje)
    });
  }

  lados(d: DetalleModificacion): { titulo: string; lado: 'origen' | 'destino'; linea: SaldoLinea | null; mes: number | null }[] {
    const m = d.modificacion;
    const lista: { titulo: string; lado: 'origen' | 'destino'; linea: SaldoLinea | null; mes: number | null }[] = [];
    if (m.origen_descripcion) {
      lista.push({ titulo: 'Emisor (origen)', lado: 'origen', linea: d.origen, mes: m.origen_mes });
    }
    if (m.destino_descripcion) {
      lista.push({ titulo: 'Receptor (destino)', lado: 'destino', linea: d.destino, mes: m.destino_mes });
    }
    return lista;
  }

  imprimir(): void {
    const folio = this.d()?.modificacion.folio_completo;
    this.servicio.pdf(this.id).subscribe({
      next: (b) => abrirBlob(b, `${folio}.pdf`),
      error: () => this.mensaje.set({ tipo: 'danger', texto: 'No se pudo generar el formato.' })
    });
  }

  resolver(accion: 'autorizar' | 'rechazar'): void {
    const m = this.d()!.modificacion;
    ResolverModificacionComponent.abrir(this.modal, { id: m.id, folio: m.folio_completo, importe: m.importe, accion }).then((texto) => {
      if (texto) {
        this.mensaje.set({ tipo: 'success', texto });
        this.cargar();
      }
    });
  }
}
