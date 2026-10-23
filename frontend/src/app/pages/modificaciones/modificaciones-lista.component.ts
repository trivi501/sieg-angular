import { Component, inject, signal } from '@angular/core';
import { DatePipe, KeyValuePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { NgbModal } from '@ng-bootstrap/ng-bootstrap';

import { AuthService } from 'src/app/core/auth/auth.service';
import { money } from 'src/app/core/formato';
import { PaginacionComponent } from '../admin/paginacion.component';
import { ListaModificaciones, Modificacion, ModificacionesService, claseEstadoModificacion } from './modificaciones.service';
import { ResolverModificacionComponent } from './resolver.component';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

/** Historial de modificaciones presupuestales, con aceptar / rechazar directo en las pendientes (SIEG: Index.tsx). */
@Component({
  selector: 'app-modificaciones-lista',
  imports: [SelectBusquedaDirective, AccionComponent, FormsModule, RouterLink, PaginacionComponent, DatePipe, KeyValuePipe],
  templateUrl: './modificaciones-lista.component.html',
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
    .linea {
      max-width: 260px;
    }
  `
})
export class ModificacionesListaComponent {
  private servicio = inject(ModificacionesService);
  private modal = inject(NgbModal);
  protected auth = inject(AuthService);

  readonly money = money;
  readonly claseEstado = claseEstadoModificacion;
  readonly sinOrden = () => 0;

  datos = signal<ListaModificaciones | null>(null);
  /** El panel de inicio llega con ?estado=pendiente. */
  filtros: Record<string, string> = { folio: '', tipo: '', estado: inject(ActivatedRoute).snapshot.queryParamMap.get('estado') ?? '', partida: '' };
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.servicio.lista({ ...this.filtros, page }).subscribe((d) => this.datos.set(d));
  }

  mes(numero: number | null): string {
    return numero ? (this.datos()?.meses[numero] ?? '') : '';
  }

  resolver(m: Modificacion, accion: 'autorizar' | 'rechazar'): void {
    ResolverModificacionComponent.abrir(this.modal, { id: m.id, folio: m.folio_completo, importe: m.importe, accion }).then((texto) => {
      if (texto) {
        this.mensaje.set({ tipo: 'success', texto });
        this.cargar(this.datos()?.current_page ?? 1);
      }
    });
  }
}
