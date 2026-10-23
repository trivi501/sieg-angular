import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';

import { CardComponent } from 'src/app/theme/shared/components/card/card.component';
import { CanDirective } from 'src/app/core/auth/can.directive';
import { Paginado, erroresDe } from 'src/app/core/api';
import { AdminService, Permiso } from '../admin.service';
import { PaginacionComponent } from '../paginacion.component';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

/**
 * Permisos con alta y edición en la misma pantalla. Recordatorio de SIEG: un permiso nuevo
 * solo sirve si alguna ruta del backend lo exige.
 */
@Component({
  selector: 'app-permisos-lista',
  imports: [SelectBusquedaDirective, AccionComponent, CardComponent, CanDirective, FormsModule, PaginacionComponent],
  templateUrl: './permisos-lista.component.html'
})
export class PermisosListaComponent {
  private admin = inject(AdminService);

  pagina = signal<Paginado<Permiso> | null>(null);
  categorias = signal<string[]>([]);
  busqueda = '';
  categoria = '';

  /** Permiso en edición (sin id = alta); null = formulario cerrado. */
  editando = signal<Partial<Permiso> | null>(null);
  errores = signal<Record<string, string>>({});
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.admin.permisos(page, this.busqueda, this.categoria).subscribe((p) => {
      this.pagina.set(p);
      this.categorias.set(p.categorias);
    });
  }

  abrir(permiso?: Permiso): void {
    this.errores.set({});
    this.editando.set(permiso ? { ...permiso } : { name: '', nombre_mostrar: '', categoria: '' });
  }

  guardar(): void {
    const p = this.editando();
    if (!p) {
      return;
    }
    const datos = { name: p.name ?? '', nombre_mostrar: p.nombre_mostrar || null, categoria: p.categoria || null };
    this.admin.guardarPermiso(datos, p.id).subscribe({
      next: (r) => {
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.editando.set(null);
        this.cargar(this.pagina()?.current_page ?? 1);
      },
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(campos);
        if (mensaje) {
          this.mensaje.set({ tipo: 'danger', texto: mensaje });
        }
      }
    });
  }

  eliminar(permiso: Permiso): void {
    if (!confirm(`¿Eliminar el permiso ${permiso.name}? Se quitará de todos los roles.`)) {
      return;
    }
    this.admin.eliminarPermiso(permiso.id).subscribe({
      next: (r) => {
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar(this.pagina()?.current_page ?? 1);
      },
      error: (e: HttpErrorResponse) => this.mensaje.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }
}
