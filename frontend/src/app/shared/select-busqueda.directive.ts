import { AfterViewInit, DestroyRef, Directive, ElementRef, inject } from '@angular/core';
import { NgControl } from '@angular/forms';
import TomSelect from 'tom-select';

/**
 * Tom Select normalmente reescribe las <option> del select original (las mueve y crea copias), y esas
 * opciones las maneja Angular (ngValue). Aquí solo se fija el valor del select y se avisa del cambio.
 */
class TomSelectAngular extends TomSelect {
  override updateOriginalInput(opts: { silent?: boolean } = {}): void {
    const select = this.input as HTMLSelectElement;
    const valor = String(this.getValue() ?? '');
    if (select.value !== valor) {
      select.value = valor;
    }
    if (this.isSetup && !opts.silent) {
      this['trigger']('change', valor);
    }
  }
}

/**
 * Convierte cualquier <select class="form-select"> en un select con búsqueda por escritura (Tom Select)
 * sin cambiar el formulario: Tom Select escribe en el <select> original y dispara "change", así que
 * ngModel / formControl siguen funcionando igual. Para excluir uno: class="sin-busqueda".
 */
@Directive({
  selector: 'select.form-select:not([multiple]):not(.sin-busqueda)'
})
export class SelectBusquedaDirective implements AfterViewInit {
  private el = inject<ElementRef<HTMLSelectElement>>(ElementRef).nativeElement;
  private control = inject(NgControl, { optional: true, self: true });
  private destroyRef = inject(DestroyRef);
  private ts?: TomSelect;

  ngAfterViewInit(): void {
    this.ts = new TomSelectAngular(this.el, {
      allowEmptyOption: true, // "Todos", "Seleccionar…" (valor vacío) también se pueden elegir
      maxOptions: undefined, // todas las opciones, no solo las primeras 50
      dropdownParent: 'body', // que no lo recorten las tablas ni las ventanas
      plugins: ['dropdown_input'], // el cuadro de búsqueda va dentro de la lista, el campo no cambia de tamaño
      placeholder: 'Escribe para buscar…',
      render: {
        no_results: () => '<div class="no-results">Sin resultados</div>'
      }
    });
    this.ajustarHabilitado();

    // Las opciones llegan después (datos de la API) o cambian: volver a leerlas del <select>.
    this.observador = new MutationObserver(() => queueMicrotask(() => this.sincronizar(true)));
    this.observar();
    queueMicrotask(() => this.sincronizar(true));

    // Cambios del modelo hechos por código (limpiar filtros, cargar un registro, etc.).
    const sub = this.control?.valueChanges?.subscribe(() => queueMicrotask(() => this.sincronizar(false)));

    this.destroyRef.onDestroy(() => {
      this.observador?.disconnect();
      sub?.unsubscribe();
      this.ts?.destroy();
    });
  }

  private observador?: MutationObserver;

  private observar(): void {
    this.observador?.observe(this.el, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['disabled', 'value', 'label'] });
  }

  private sincronizar(opciones: boolean): void {
    const ts = this.ts;
    if (!ts) return;
    // Tom Select también escribe en el <select>: sin pausar el observador se volvería a disparar sin fin.
    this.observador?.disconnect();
    this.ajustarHabilitado();
    const valor = this.el.value;
    if (opciones) {
      // Se copian a mano: ts.sync() descarta la opción de valor vacío ("Todos", "Seleccionar…").
      ts.clear(true);
      ts.clearOptions();
      ts.addOptions([...this.el.options].map((o, i) => ({ value: o.value, text: o.text, disabled: o.disabled, $order: i + 1 })));
      ts.refreshOptions(false);
      ts.setValue(valor, true); // también vuelve a pintar el texto si la opción elegida cambió de nombre
    } else if (ts.getValue() !== valor) {
      ts.setValue(valor, true);
    }
    this.observador?.takeRecords();
    this.observar();
  }

  private ajustarHabilitado(): void {
    if (this.el.disabled) this.ts?.disable();
    else this.ts?.enable();
  }
}
