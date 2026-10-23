import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { CardComponent } from 'src/app/theme/shared/components/card/card.component';
import { erroresDe } from 'src/app/core/api';
import { AdminService, Permiso } from '../admin.service';

/** Alta y edición de rol con sus permisos agrupados por categoría (SIEG: settings/roles/create y edit). */
@Component({
  selector: 'app-rol-form',
  imports: [CardComponent, FormsModule, RouterLink],
  templateUrl: './rol-form.component.html'
})
export class RolFormComponent {
  private admin = inject(AdminService);
  private router = inject(Router);

  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id')) || undefined;

  nombre = signal('');
  seleccionados = signal(new Set<number>());
  permisos = signal<Permiso[]>([]);
  filtro = signal('');
  errores = signal<Record<string, string>>({});
  error = signal('');
  guardando = signal(false);

  /** Permisos que coinciden con el filtro, agrupados por categoría ("Sin categoría" al final). */
  grupos = computed(() => {
    const texto = this.filtro().trim().toLowerCase();
    const mapa = new Map<string, Permiso[]>();
    for (const p of this.permisos()) {
      if (texto && !`${p.name} ${p.nombre_mostrar ?? ''}`.toLowerCase().includes(texto)) {
        continue;
      }
      const categoria = p.categoria || 'Sin categoría';
      mapa.set(categoria, [...(mapa.get(categoria) ?? []), p]);
    }
    return [...mapa.entries()]
      .sort(([a], [b]) => (a === 'Sin categoría' ? 1 : b === 'Sin categoría' ? -1 : a.localeCompare(b)))
      .map(([categoria, items]) => ({ categoria, items }));
  });

  constructor() {
    this.admin.permisosParaRol().subscribe((r) => this.permisos.set(r.permissions));
    if (this.id) {
      this.admin.rol(this.id).subscribe((r) => {
        this.nombre.set(r.name);
        this.seleccionados.set(new Set(r.permissions));
      });
    }
  }

  alternar(id: number, marcado: boolean): void {
    const nuevo = new Set(this.seleccionados());
    if (marcado) {
      nuevo.add(id);
    } else {
      nuevo.delete(id);
    }
    this.seleccionados.set(nuevo);
  }

  todosMarcados(items: Permiso[]): boolean {
    return items.every((p) => this.seleccionados().has(p.id));
  }

  alternarGrupo(items: Permiso[], marcado: boolean): void {
    const nuevo = new Set(this.seleccionados());
    for (const p of items) {
      if (marcado) {
        nuevo.add(p.id);
      } else {
        nuevo.delete(p.id);
      }
    }
    this.seleccionados.set(nuevo);
  }

  guardar(): void {
    this.guardando.set(true);
    this.errores.set({});
    this.error.set('');

    this.admin.guardarRol({ name: this.nombre(), permissions: [...this.seleccionados()] }, this.id).subscribe({
      next: () => this.router.navigate(['/roles']),
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(campos);
        this.error.set(mensaje);
        this.guardando.set(false);
      }
    });
  }
}
