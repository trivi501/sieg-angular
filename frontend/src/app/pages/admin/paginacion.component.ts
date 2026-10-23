import { Component, input, output } from '@angular/core';

/** Paginación para respuestas `paginate()` de Laravel. */
@Component({
  selector: 'app-paginacion',
  template: `
    @if (ultima() > 1) {
      <div class="d-flex justify-content-between align-items-center mt-3">
        <small class="text-muted">Página {{ actual() }} de {{ ultima() }} · {{ total() }} registros</small>
        <div class="btn-group">
          <button type="button" class="btn btn-sm btn-outline-secondary" [disabled]="actual() <= 1" (click)="cambiar.emit(actual() - 1)">
            Anterior
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary" [disabled]="actual() >= ultima()" (click)="cambiar.emit(actual() + 1)">
            Siguiente
          </button>
        </div>
      </div>
    }
  `
})
export class PaginacionComponent {
  actual = input.required<number>();
  ultima = input.required<number>();
  total = input.required<number>();
  cambiar = output<number>();
}
