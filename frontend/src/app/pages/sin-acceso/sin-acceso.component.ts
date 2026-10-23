import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { CardComponent } from 'src/app/theme/shared/components/card/card.component';
import { AccionComponent } from 'src/app/shared/accion.component';

@Component({
  selector: 'app-sin-acceso',
  imports: [AccionComponent, CardComponent, RouterLink],
  template: `
    <app-card cardTitle="Sin acceso">
      <p>No tienes permiso para ver esta sección. Si lo necesitas, pídelo al administrador del sistema.</p>
      <a routerLink="/inicio" class="btn btn-primary" appAccion="Ir al inicio" icono="home"></a>
    </app-card>
  `
})
export class SinAccesoComponent {}
