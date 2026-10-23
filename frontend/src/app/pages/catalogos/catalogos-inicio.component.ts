import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { CatalogoResumen, CatalogosService } from './catalogos.service';

/** Catálogos que el usuario puede ver, agrupados (SIEG: pages/catalogos/Index.tsx). */
@Component({
  selector: 'app-catalogos-inicio',
  imports: [RouterLink],
  template: `
    <div class="mb-3">
      <h4 class="mb-1">Catálogos generales</h4>
      <p class="text-muted mb-0">Altas, cambios, bajas e importación desde Excel. Cada cambio queda en el historial del registro.</p>
    </div>

    @if (cargado() && !grupos().length) {
      <div class="card"><div class="card-body text-muted">No tienes acceso a ningún catálogo.</div></div>
    }

    @for (g of grupos(); track g.grupo) {
      <h6 class="text-uppercase text-muted mt-4 mb-2">{{ g.grupo }}</h6>
      <div class="row g-3">
        @for (c of g.catalogos; track c.slug) {
          <div class="col-md-6 col-xl-4">
            <a [routerLink]="['/catalogos', c.slug]" class="card h-100 text-reset text-decoration-none tarjeta-catalogo mb-0">
              <div class="card-body">
                <h5 class="mb-2">{{ c.titulo }}</h5>
                @if (c.descripcion) {
                  <p class="text-muted small mb-2">{{ c.descripcion }}</p>
                }
                <p class="mb-0 fw-semibold">
                  {{ c.total }} registros{{ c.activos !== null ? ' · ' + c.activos + ' activos' : '' }}
                </p>
              </div>
            </a>
          </div>
        }
      </div>
    }
  `,
  styles: `
    .tarjeta-catalogo:hover {
      background: var(--bs-light, #f8f9fa);
    }
  `
})
export class CatalogosInicioComponent {
  private datos = signal<{ grupos: string[]; catalogos: CatalogoResumen[] } | null>(null);
  cargado = computed(() => this.datos() !== null);

  grupos = computed(() => {
    const d = this.datos();
    if (!d) {
      return [];
    }
    return d.grupos
      .map((grupo) => ({ grupo, catalogos: d.catalogos.filter((c) => c.grupo === grupo) }))
      .filter((g) => g.catalogos.length);
  });

  constructor() {
    inject(CatalogosService)
      .inicio()
      .subscribe((d) => this.datos.set(d));
  }
}
