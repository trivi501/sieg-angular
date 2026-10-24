import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { CanDirective } from 'src/app/core/auth/can.directive';
import { money } from 'src/app/core/formato';
import { PaginacionComponent } from '../admin/paginacion.component';
import { ListaRequisiciones, RequisicionesService, claseEstado } from './requisiciones.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { AccionComponent } from 'src/app/shared/accion.component';
import { MenuRequisicionComponent } from './menu-requisicion.component';

/**
 * Requisiciones de la secretaría del usuario (Admin y Control Presupuestal: todas) o, con
 * data.bandeja = 'recursos-materiales', la bandeja de Recursos Materiales (de todas las secretarías).
 */
@Component({
  selector: 'app-requisiciones-lista',
  imports: [MenuRequisicionComponent, AccionComponent, SelectBusquedaDirective, FormsModule, RouterLink, PaginacionComponent, CanDirective, DatePipe],
  templateUrl: './requisiciones-lista.component.html',
  styles: `
    td,
    th {
      font-size: 13px;
    }
    .fila-menu {
      cursor: context-menu;
    }
    .concepto {
      max-width: 280px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  `
})
export class RequisicionesListaComponent {
  private servicio = inject(RequisicionesService);

  private readonly ruta = inject(ActivatedRoute).snapshot;
  readonly bandeja: 'requisiciones' | 'recursos-materiales' = this.ruta.data['bandeja'] ?? 'requisiciones';
  readonly money = money;
  readonly claseEstado = claseEstado;

  datos = signal<ListaRequisiciones | null>(null);
  /** Resultado de una acción del menú contextual (p. ej. eliminar). */
  aviso = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);
  /** El panel de inicio llega con ?estado= ya filtrado. */
  filtros: Record<string, string> = { folio_completo: '', unidad: '', concepto: '', estado: this.ruta.queryParamMap.get('estado') ?? '', año: '' };

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.lista(this.bandeja, { ...this.filtros, page }).subscribe((d) => this.datos.set(d));
  }

  limpiar(): void {
    for (const k of Object.keys(this.filtros)) {
      this.filtros[k] = '';
    }
    this.cargar(1);
  }
}
