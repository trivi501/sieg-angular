import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { CardComponent } from 'src/app/theme/shared/components/card/card.component';
import { erroresDe } from 'src/app/core/api';
import { AdminService, OpcionesUsuario, UsuarioDetalle } from '../admin.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

/** Alta y edición de usuario (SIEG: settings/users/create y edit). */
@Component({
  selector: 'app-usuario-form',
  imports: [SelectBusquedaDirective, CardComponent, ReactiveFormsModule, RouterLink],
  templateUrl: './usuario-form.component.html'
})
export class UsuarioFormComponent {
  private admin = inject(AdminService);
  private router = inject(Router);

  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id')) || undefined;

  form = inject(FormBuilder).group({
    name: ['', Validators.required],
    titulo: [''],
    cargo: [''],
    email: ['', [Validators.required, Validators.email]],
    // En la edición la contraseña es opcional: vacía no se cambia.
    password: ['', this.id ? [Validators.minLength(10)] : [Validators.required, Validators.minLength(10)]],
    pertenece: ['secretaria' as 'secretaria' | 'regidor'],
    id_cat_egreso_unidad_administrativa: [null as number | null],
    regidor_id: [null as number | null],
    roles: [[] as number[]]
  });

  opciones = signal<OpcionesUsuario>({ roles: [], unidades: [], regidores: [] });
  errores = signal<Record<string, string>>({});
  error = signal('');
  guardando = signal(false);

  constructor() {
    this.admin.opcionesUsuario(this.id).subscribe((o) => this.opciones.set(o));
    if (this.id) {
      this.admin.usuario(this.id).subscribe((u) => this.form.patchValue({ ...u, password: '' }));
    }
  }

  tieneRol(id: number): boolean {
    return this.form.controls.roles.value?.includes(id) ?? false;
  }

  alternarRol(id: number, marcado: boolean): void {
    const actuales = this.form.controls.roles.value ?? [];
    this.form.controls.roles.setValue(marcado ? [...actuales, id] : actuales.filter((r) => r !== id));
  }

  guardar(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.guardando.set(true);
    this.errores.set({});
    this.error.set('');

    this.admin.guardarUsuario(this.form.getRawValue() as UsuarioDetalle, this.id).subscribe({
      next: () => this.router.navigate(['/usuarios']),
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(campos);
        this.error.set(mensaje);
        this.guardando.set(false);
      }
    });
  }
}
