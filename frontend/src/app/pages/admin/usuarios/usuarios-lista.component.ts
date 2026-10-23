import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { CardComponent } from 'src/app/theme/shared/components/card/card.component';
import { CanDirective } from 'src/app/core/auth/can.directive';
import { AuthService } from 'src/app/core/auth/auth.service';
import { Paginado, erroresDe } from 'src/app/core/api';
import { AdminService, UsuarioFila } from '../admin.service';
import { PaginacionComponent } from '../paginacion.component';
import { AccionComponent } from 'src/app/shared/accion.component';

@Component({
  selector: 'app-usuarios-lista',
  imports: [AccionComponent, CardComponent, CanDirective, FormsModule, RouterLink, PaginacionComponent],
  templateUrl: './usuarios-lista.component.html'
})
export class UsuariosListaComponent {
  private admin = inject(AdminService);
  protected auth = inject(AuthService);

  pagina = signal<Paginado<UsuarioFila> | null>(null);
  busqueda = '';
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    this.admin.usuarios(page, this.busqueda).subscribe((p) => this.pagina.set(p));
  }

  eliminar(usuario: UsuarioFila): void {
    if (!confirm(`¿Eliminar al usuario ${usuario.name}? Esta acción no se puede deshacer.`)) {
      return;
    }
    this.admin.eliminarUsuario(usuario.id).subscribe({
      next: (r) => {
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar(this.pagina()?.current_page ?? 1);
      },
      error: (e: HttpErrorResponse) => this.mensaje.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }
}
