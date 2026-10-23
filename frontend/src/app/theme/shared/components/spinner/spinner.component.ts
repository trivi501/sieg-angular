// Angular import
import { Component, DestroyRef, ViewEncapsulation, effect, inject, signal } from '@angular/core';
import { Router, NavigationStart, NavigationEnd, NavigationCancel, NavigationError } from '@angular/router';

// project import
import { LoadingService } from 'src/app/core/loading.service';

/** Loader del template: emblema del Ayuntamiento mientras se cambia de página o hay peticiones en curso. */
@Component({
  selector: 'app-spinner',
  templateUrl: './spinner.component.html',
  styleUrls: ['./spinner.component.scss'],
  encapsulation: ViewEncapsulation.None
})
export class SpinnerComponent {
  private router = inject(Router);
  private loading = inject(LoadingService);

  /** Se muestra solo si la espera pasa de este tiempo, para que no parpadee en respuestas rápidas. */
  private static readonly RETRASO_MS = 250;

  isSpinnerVisible = signal(true);
  private navegando = false;

  constructor() {
    const sub = this.router.events.subscribe((event) => {
      if (event instanceof NavigationStart && !this.navegando) {
        this.navegando = true;
        this.loading.iniciar();
      } else if (
        (event instanceof NavigationEnd || event instanceof NavigationCancel || event instanceof NavigationError) &&
        this.navegando
      ) {
        this.navegando = false;
        this.loading.terminar();
      }
    });
    inject(DestroyRef).onDestroy(() => sub.unsubscribe());

    effect((onCleanup) => {
      if (!this.loading.ocupado()) {
        this.isSpinnerVisible.set(false);
        return;
      }
      const t = setTimeout(() => this.isSpinnerVisible.set(true), SpinnerComponent.RETRASO_MS);
      onCleanup(() => clearTimeout(t));
    });
  }
}
