import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { RouterLink } from '@angular/router';

import { CardComponent } from 'src/app/theme/shared/components/card/card.component';
import { CanDirective } from 'src/app/core/auth/can.directive';
import { erroresDe } from 'src/app/core/api';
import { AdminService, RolFila } from '../admin.service';
import { AccionComponent } from 'src/app/shared/accion.component';

@Component({
  selector: 'app-roles-lista',
  imports: [AccionComponent, CardComponent, CanDirective, RouterLink],
  template: `
    <app-card cardTitle="Roles">
      @if (mensaje(); as m) {
        <div class="alert alert-{{ m.tipo }} alert-dismissible py-2">
          {{ m.texto }}
          <button type="button" class="btn-close" (click)="mensaje.set(null)"></button>
        </div>
      }
      <div class="d-flex justify-content-end mb-3">
        <a *appCan="'roles-create'" routerLink="/roles/nuevo" class="btn btn-primary" appAccion="Nuevo rol" icono="plus"></a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Rol</th>
              <th class="text-center">Permisos</th>
              <th class="text-center">Usuarios</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            @for (r of roles(); track r.id) {
              <tr>
                <td class="fw-semibold">{{ r.name }}</td>
                <td class="text-center">{{ r.permissions_count }}</td>
                <td class="text-center">{{ r.users_count }}</td>
                <td class="text-end text-nowrap">
                  <a *appCan="'roles-edit'" [routerLink]="['/roles', r.id]" class="btn btn-sm btn-outline-primary" appAccion="Editar" icono="edit"></a>
                  <button *appCan="'roles-delete'" type="button" class="btn btn-sm btn-outline-danger" (click)="eliminar(r)" appAccion="Eliminar" icono="delete"></button>
                </td>
              </tr>
            } @empty {
              <tr>
                <td colspan="4" class="text-center text-muted py-4">Cargando…</td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    </app-card>
  `
})
export class RolesListaComponent {
  private admin = inject(AdminService);

  roles = signal<RolFila[]>([]);
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.admin.roles().subscribe((r) => this.roles.set(r));
  }

  eliminar(rol: RolFila): void {
    const aviso = rol.users_count ? ` Lo tienen ${rol.users_count} usuario(s), que perderán sus permisos.` : '';
    if (!confirm(`¿Eliminar el rol ${rol.name}?${aviso}`)) {
      return;
    }
    this.admin.eliminarRol(rol.id).subscribe({
      next: (r) => {
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.mensaje.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }
}
