import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { NgbActiveModal, NgbModal } from '@ng-bootstrap/ng-bootstrap';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { ModificacionesService } from './modificaciones.service';

/**
 * Diálogo para aceptar o rechazar una modificación (Jefe de Control Presupuestal). Al aceptar, el
 * servidor vuelve a revisar el disponible del emisor; el rechazo exige el motivo.
 */
@Component({
  selector: 'app-resolver-modificacion',
  imports: [FormsModule],
  template: `
    <div class="modal-header">
      <h5 class="modal-title">{{ accion() === 'autorizar' ? 'Aceptar' : 'Rechazar' }} {{ folio() }}</h5>
      <button type="button" class="btn-close" (click)="ventana.dismiss()"></button>
    </div>
    <div class="modal-body">
      <p class="small text-muted">
        @if (accion() === 'autorizar') {
          Por {{ money(importe()) }}. Se vuelve a revisar el disponible del emisor (anual y del mes); el presupuesto vigente cambia de inmediato.
        } @else {
          Indica el motivo por el que no procede; el área lo verá en la modificación.
        }
      </p>
      <label class="form-label" for="obs-mod">{{ accion() === 'autorizar' ? 'Observaciones (opcional)' : 'Motivo' }}</label>
      <textarea id="obs-mod" rows="3" class="form-control" [(ngModel)]="observaciones"></textarea>
      @if (error()) {
        <div class="alert alert-danger py-2 small mt-3 mb-0">{{ error() }}</div>
      }
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" (click)="ventana.dismiss()">Cerrar</button>
      <button
        type="button"
        class="btn"
        [class.btn-success]="accion() === 'autorizar'"
        [class.btn-danger]="accion() === 'rechazar'"
        [disabled]="ocupado()"
        (click)="enviar()"
      >
        {{ ocupado() ? 'Procesando…' : accion() === 'autorizar' ? 'Aceptar' : 'Rechazar' }}
      </button>
    </div>
  `
})
export class ResolverModificacionComponent {
  private servicio = inject(ModificacionesService);
  protected ventana = inject(NgbActiveModal);

  readonly money = money;

  /** Se asignan al abrir el diálogo (NgbModal → componentInstance). */
  id = signal(0);
  folio = signal('');
  importe = signal(0);
  accion = signal<'autorizar' | 'rechazar'>('autorizar');

  observaciones = '';
  error = signal('');
  ocupado = signal(false);

  /** Abre el diálogo y devuelve el mensaje del servidor al resolver (o nada si se cierra). */
  static abrir(modal: NgbModal, datos: { id: number; folio: string; importe: number; accion: 'autorizar' | 'rechazar' }): Promise<string | undefined> {
    const ref = modal.open(ResolverModificacionComponent);
    const c = ref.componentInstance as ResolverModificacionComponent;
    c.id.set(datos.id);
    c.folio.set(datos.folio);
    c.importe.set(datos.importe);
    c.accion.set(datos.accion);
    return ref.result.then(
      (texto: string) => texto,
      () => undefined
    );
  }

  enviar(): void {
    this.ocupado.set(true);
    this.error.set('');
    this.servicio.resolver(this.id(), this.accion(), this.observaciones).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.ventana.close(r.message);
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.error.set(campos['observaciones'] ?? mensaje);
      }
    });
  }
}
