import { Directive, TemplateRef, ViewContainerRef, effect, inject, input } from '@angular/core';

import { AuthService } from './auth.service';

/**
 * Muestra el contenido solo si el usuario tiene el permiso (o alguno de la lista):
 * `<button *appCan="'users-create'">`. En SIEG era `userPermissions.includes(...)`.
 */
@Directive({ selector: '[appCan]' })
export class CanDirective {
  private auth = inject(AuthService);
  private template = inject(TemplateRef<unknown>);
  private vista = inject(ViewContainerRef);

  readonly appCan = input.required<string | string[]>();

  constructor() {
    effect(() => {
      this.vista.clear();
      if (this.auth.can(this.appCan())) {
        this.vista.createEmbeddedView(this.template);
      }
    });
  }
}
