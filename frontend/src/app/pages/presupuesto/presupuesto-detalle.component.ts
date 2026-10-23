import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { AuthService } from 'src/app/core/auth/auth.service';
import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { DetallePresupuesto, MESES, PresupuestoService } from './presupuesto.service';
import { AccionComponent } from 'src/app/shared/accion.component';

/**
 * Una línea del presupuesto: momentos del gasto anuales y, mes por mes, saldo corrido, calendario,
 * modificado, comprometido y disponible del mes (SIEG: SaldosMensuales).
 */
@Component({
  selector: 'app-presupuesto-detalle',
  imports: [AccionComponent, RouterLink],
  templateUrl: './presupuesto-detalle.component.html'
})
export class PresupuestoDetalleComponent {
  private auth = inject(AuthService);
  readonly money = money;
  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));

  detalle = signal<DetallePresupuesto | null>(null);
  error = signal('');

  /** Editar exige el permiso y además rol Admin (igual que el backend). */
  puedeEditar = computed(() => this.auth.can('presupuesto-edit') && this.auth.hasRole('Super Admin', 'Admin'));

  momentos = computed(() => {
    const s = this.detalle()?.saldos ?? {};
    return [
      { etiqueta: 'Aprobado', valor: s.asignado },
      { etiqueta: 'Modificado', valor: s.modificado },
      { etiqueta: 'Vigente', valor: s.vigente },
      { etiqueta: 'En trámite', valor: s.en_tramite },
      { etiqueta: 'Comprometido', valor: s.comprometido },
      { etiqueta: 'Devengado', valor: s.devengado },
      { etiqueta: 'Pagado', valor: s.pagado },
      { etiqueta: 'Disponible', valor: s.disponible }
    ];
  });

  totalMeses = computed(() => {
    const meses = this.detalle()?.meses ?? [];
    return {
      asignado: meses.reduce((t, m) => t + m.asignado, 0),
      modificado: meses.reduce((t, m) => t + m.modificado, 0),
      comprometido: meses.reduce((t, m) => t + m.comprometido, 0)
    };
  });

  constructor() {
    inject(PresupuestoService)
      .detalle(this.id)
      .subscribe({
        next: (d) => this.detalle.set(d),
        error: (e: HttpErrorResponse) =>
          this.error.set(e.status === 404 ? 'La línea no existe.' : e.status === 403 ? 'Esta línea no es de tu secretaría o dirección.' : erroresDe(e).mensaje)
      });
  }

  /** Columna del calendario autorizado (ENERO…DIC) del mes 1–12. */
  calendario(mes: number): number {
    const linea = this.detalle()?.linea;
    return Number(linea?.[MESES[mes - 1].key] ?? 0);
  }
}
