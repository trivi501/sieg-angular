import { Component, Injectable, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { NgbActiveModal, NgbModal } from '@ng-bootstrap/ng-bootstrap';

export interface OpcionesConfirmar {
  titulo?: string;
  /** Texto del botón que acepta ("Eliminar", "Validar"…). */
  aceptar?: string;
  cancelar?: string;
  /** Acción destructiva: el botón de aceptar va en rojo. */
  peligro?: boolean;
  /** Para acciones graves: hay que escribir este texto exacto para poder aceptar. */
  escribir?: string;
}

/** Cuadro de confirmación modal (sustituye a confirm()/prompt() del navegador). */
@Component({
  selector: 'app-dialogo-confirmar',
  imports: [FormsModule],
  template: `
    <div class="modal-header">
      <h5 class="modal-title">{{ titulo() }}</h5>
      <button type="button" class="btn-close" aria-label="Cerrar" (click)="modal.dismiss()"></button>
    </div>
    <div class="modal-body">
      <p class="mb-0" style="white-space: pre-line">{{ mensaje() }}</p>
      @if (escribir(); as texto) {
        <label class="form-label mt-3 small" for="dlg-escribir">Escribe <b>{{ texto }}</b> para confirmar</label>
        <input id="dlg-escribir" class="form-control" autocomplete="off" [ngModel]="escrito()" (ngModelChange)="escrito.set($event)"
          (keydown.enter)="puedeAceptar() && modal.close(true)" />
      }
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" (click)="modal.dismiss()">{{ cancelar() }}</button>
      <button type="button" class="btn" [class.btn-danger]="peligro()" [class.btn-primary]="!peligro()" [disabled]="!puedeAceptar()"
        (click)="modal.close(true)" ngbAutofocus>{{ aceptar() }}</button>
    </div>
  `,
})
export class DialogoConfirmarComponent {
  modal = inject(NgbActiveModal);
  titulo = signal('Confirmar');
  mensaje = signal('');
  aceptar = signal('Aceptar');
  cancelar = signal('Cancelar');
  peligro = signal(false);
  escribir = signal<string | undefined>(undefined);
  escrito = signal('');

  puedeAceptar(): boolean {
    const texto = this.escribir();
    return !texto || this.escrito().trim() === texto;
  }
}

@Injectable({ providedIn: 'root' })
export class DialogoService {
  private modal = inject(NgbModal);

  /** Abre la confirmación y resuelve true si se acepta, false si se cancela o se cierra. */
  confirmar(mensaje: string, opciones: OpcionesConfirmar = {}): Promise<boolean> {
    const ref = this.modal.open(DialogoConfirmarComponent, { centered: true, backdrop: 'static' });
    const c = ref.componentInstance as DialogoConfirmarComponent;
    c.mensaje.set(mensaje);
    c.titulo.set(opciones.titulo ?? (opciones.peligro ? 'Confirmar eliminación' : 'Confirmar'));
    c.aceptar.set(opciones.aceptar ?? (opciones.peligro ? 'Eliminar' : 'Aceptar'));
    c.cancelar.set(opciones.cancelar ?? 'Cancelar');
    c.peligro.set(opciones.peligro ?? false);
    c.escribir.set(opciones.escribir);
    return ref.result.then(
      (r) => r === true,
      () => false,
    );
  }
}
