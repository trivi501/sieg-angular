import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { centavos, money } from 'src/app/core/formato';
import { CatalogosPresupuesto, MESES, PresupuestoService } from './presupuesto.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

/**
 * Alta y edición de una línea del presupuesto (SIEG: components/presupuesto-linea-form.tsx).
 * Las claves salen de los catálogos y los 12 meses deben sumar exactamente el importe total.
 */
@Component({
  selector: 'app-presupuesto-form',
  imports: [SelectBusquedaDirective, FormsModule, RouterLink],
  templateUrl: './presupuesto-form.component.html'
})
export class PresupuestoFormComponent {
  private servicio = inject(PresupuestoService);
  private router = inject(Router);

  readonly money = money;
  readonly meses = MESES;
  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id')) || undefined;

  catalogos = signal<CatalogosPresupuesto>({ unidades: [], fuentes: [], proyectos: [], partidas: [] });

  claves = signal({ CLAVE: '', CLAVE3: '', CLAVE6: '', PARTIDA: '' });
  total = signal<number | null>(null);
  importes = signal<Record<string, number | null>>(Object.fromEntries(MESES.map((m) => [m.key, null])));

  errores = signal<Record<string, string>>({});
  error = signal('');
  guardando = signal(false);

  /** Lo distribuido y la diferencia contra el total, en centavos. */
  distribuido = computed(() => MESES.reduce((t, m) => t + centavos(this.importes()[m.key]), 0));
  diferencia = computed(() => centavos(this.total()) - this.distribuido());

  partidaElegida = computed(() => this.catalogos().partidas.find((p) => p.clave === this.claves().PARTIDA));

  constructor() {
    this.servicio.catalogos().subscribe((c) => this.catalogos.set(c));

    if (this.id) {
      this.servicio.detalle(this.id).subscribe(({ linea }) => {
        this.claves.set({ CLAVE: linea.CLAVE?.trim(), CLAVE3: linea.CLAVE3?.trim(), CLAVE6: linea.CLAVE6?.trim(), PARTIDA: linea.PARTIDA?.trim() });
        this.total.set(Number(linea.IMPORTE_TOTAL ?? 0));
        this.importes.set(Object.fromEntries(MESES.map((m) => [m.key, Number(linea[m.key] ?? 0)])));
      });
    }
  }

  cambiarClave(campo: 'CLAVE' | 'CLAVE3' | 'CLAVE6' | 'PARTIDA', valor: string): void {
    this.claves.update((c) => ({ ...c, [campo]: valor }));
  }

  cambiarMes(key: string, valor: number | null): void {
    this.importes.update((i) => ({ ...i, [key]: valor }));
  }

  /** Reparte el total en 12 meses iguales; los centavos que sobran van a los primeros meses. */
  repartir(): void {
    const total = centavos(this.total());
    const base = Math.floor(total / 12);
    const resto = total - base * 12;
    this.importes.set(Object.fromEntries(MESES.map((m, i) => [m.key, (base + (i < resto ? 1 : 0)) / 100])));
  }

  guardar(): void {
    this.guardando.set(true);
    this.errores.set({});
    this.error.set('');

    const datos = { ...this.claves(), IMPORTE_TOTAL: this.total(), ...this.importes() };

    this.servicio.guardar(datos, this.id).subscribe({
      next: () => {
        const c = this.claves();
        this.router.navigate(['/presupuesto'], this.id ? {} : { queryParams: { CLAVE: c.CLAVE, PARTIDA: c.PARTIDA } });
      },
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(campos);
        this.error.set(mensaje);
        this.guardando.set(false);
      }
    });
  }
}
