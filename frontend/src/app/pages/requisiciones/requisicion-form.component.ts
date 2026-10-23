import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { FilaCaptura, LineaCaptura, OpcionesCaptura, RequisicionesService, UnidadCaptura } from './requisiciones.service';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

const IVA = 0.16;

/**
 * Captura de la requisición (alta, o edición pendiente / rechazada) y cotización de Recursos
 * Materiales (precio unitario e IVA por concepto). SIEG: Create.tsx y Edit.tsx.
 *
 * Cada concepto elige Partida / Proyecto / Fuente encadenadas: cada columna solo ofrece valores que
 * combinan con lo ya elegido y que tienen disponible (SIEG: LineaPresupuestoCeldas).
 */
@Component({
  selector: 'app-requisicion-form',
  imports: [SelectBusquedaDirective, AccionComponent, FormsModule, RouterLink],
  templateUrl: './requisicion-form.component.html'
})
export class RequisicionFormComponent {
  private servicio = inject(RequisicionesService);
  private router = inject(Router);

  readonly money = money;
  private readonly ruta = inject(ActivatedRoute).snapshot;
  readonly id = Number(this.ruta.paramMap.get('id')) || undefined;
  /** Desde Mantenimiento vehicular (?mantenimiento=). */
  private readonly mantenimientoId = Number(this.ruta.queryParamMap.get('mantenimiento')) || undefined;

  cargado = signal(false);
  cotizacion = signal(false);
  folio = signal('');
  unidades = signal<UnidadCaptura[]>([]);
  lineas = signal<LineaCaptura[]>([]);
  periodo = signal<OpcionesCaptura['periodoCaptura'] | null>(null);
  mantenimiento = signal<OpcionesCaptura['mantenimiento']>(null);

  unidad = signal<number | null>(null);
  fecha = signal(new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' }));
  concepto = signal('');
  filas = signal<FilaCaptura[]>([this.filaVacia()]);

  errores = signal<Record<string, string>>({});
  error = signal('');
  guardando = signal(false);

  /** Líneas de la unidad elegida. */
  lineasUnidad = computed(() => {
    const clave = this.unidades().find((u) => u.id === this.unidad())?.clave;
    return this.lineas().filter((l) => l.unidad_clave === clave);
  });

  totales = computed(() => {
    let subTotal = 0;
    let iva = 0;
    for (const f of this.filas()) {
      const st = (Number(f.precio_unitario) || 0) * (Number(f.cantidad) || 0);
      subTotal += st;
      iva += f.lleva_iva ? st * IVA : 0;
    }
    return { subTotal, iva, total: subTotal + iva };
  });

  constructor() {
    if (this.id) {
      this.servicio.edicion(this.id).subscribe({
        next: (e) => {
          this.cotizacion.set(e.cotizacion);
          this.folio.set(e.requisicion.folio);
          this.unidades.set(e.unidades);
          this.lineas.set(e.lineas);
          this.unidad.set(e.requisicion.id_cat_egreso_unidad_administrativa);
          this.fecha.set(e.requisicion.fecha_tramite);
          this.concepto.set(e.requisicion.concepto ?? '');
          this.filas.set(e.requisicion.filas.map((f) => ({ ...f })));
          this.cargado.set(true);
        },
        error: (e: HttpErrorResponse) => {
          this.error.set(erroresDe(e).mensaje);
          this.cargado.set(true);
        }
      });
    } else {
      this.servicio.opciones(this.mantenimientoId).subscribe((o) => {
        this.unidades.set(o.unidades);
        this.lineas.set(o.lineas);
        this.periodo.set(o.periodoCaptura);
        this.mantenimiento.set(o.mantenimiento);
        this.unidad.set(o.unidadDefault ?? (o.unidades.length === 1 ? o.unidades[0].id : null));
        if (o.mantenimiento) {
          this.concepto.set(`MANTENIMIENTO ${o.mantenimiento.tipo} — ${o.mantenimiento.vehiculo}`.toUpperCase());
        }
        this.cargado.set(true);
      });
    }
  }

  private filaVacia(): FilaCaptura {
    return { partida: '', proyecto: '', fuente: '', cantidad: 1, descripcion: '' };
  }

  // --- Partida / Proyecto / Fuente encadenadas ---

  /** Con disponible, o la que ya tenía el concepto (para no perderla al editar). */
  private elegibles(fila: FilaCaptura): LineaCaptura[] {
    return this.lineasUnidad().filter(
      (l) => l.disponible > 0 || (l.partida === fila.partida && l.proyecto === fila.proyecto && l.fuente === fila.fuente)
    );
  }

  private unicos(lineas: LineaCaptura[], campo: 'partida' | 'proyecto' | 'fuente', nombre: keyof LineaCaptura) {
    const vistos = new Map<string, string>();
    for (const l of lineas) {
      if (!vistos.has(l[campo])) {
        vistos.set(l[campo], String(l[nombre]));
      }
    }
    return [...vistos.entries()].map(([clave, nombreTexto]) => ({ clave, nombre: nombreTexto })).sort((a, b) => a.clave.localeCompare(b.clave));
  }

  partidas(fila: FilaCaptura) {
    return this.unicos(this.elegibles(fila), 'partida', 'nombre_partida');
  }

  proyectos(fila: FilaCaptura) {
    return this.unicos(this.elegibles(fila).filter((l) => l.partida === fila.partida), 'proyecto', 'nombre_proyecto');
  }

  fuentes(fila: FilaCaptura) {
    return this.unicos(
      this.elegibles(fila).filter((l) => l.partida === fila.partida && l.proyecto === fila.proyecto),
      'fuente',
      'nombre_fuente'
    );
  }

  /** Línea completa elegida en el concepto (para mostrar su disponible). */
  lineaDe(fila: FilaCaptura): LineaCaptura | undefined {
    return this.lineasUnidad().find((l) => l.partida === fila.partida && l.proyecto === fila.proyecto && l.fuente === fila.fuente);
  }

  cambiar(i: number, campo: keyof FilaCaptura, valor: unknown): void {
    this.filas.update((filas) => {
      const copia = filas.map((f) => ({ ...f }));
      const fila = copia[i] as unknown as Record<string, unknown>;
      fila[campo] = valor;

      // Al cambiar una columna se limpian las siguientes; si solo queda una opción, se elige sola.
      if (campo === 'partida') {
        copia[i].proyecto = '';
        copia[i].fuente = '';
      }
      if (campo === 'partida' || campo === 'proyecto') {
        copia[i].fuente = campo === 'proyecto' ? '' : copia[i].fuente;
        const proyectos = this.proyectos(copia[i]);
        if (!copia[i].proyecto && proyectos.length === 1) {
          copia[i].proyecto = proyectos[0].clave;
        }
        const fuentes = this.fuentes(copia[i]);
        if (!copia[i].fuente && fuentes.length === 1) {
          copia[i].fuente = fuentes[0].clave;
        }
      }
      return copia;
    });
  }

  cambiarUnidad(id: number | null): void {
    this.unidad.set(id);
    // Las líneas son de la unidad: al cambiarla se vuelven a elegir.
    this.filas.update((filas) => filas.map((f) => ({ ...f, partida: '', proyecto: '', fuente: '' })));
  }

  agregar(): void {
    this.filas.update((f) => [...f, this.filaVacia()]);
  }

  quitar(i: number): void {
    this.filas.update((f) => f.filter((_, j) => j !== i));
  }

  subTotalFila(f: FilaCaptura): number {
    return (Number(f.precio_unitario) || 0) * (Number(f.cantidad) || 0);
  }

  error_(clave: string): string | undefined {
    return this.errores()[clave];
  }

  guardar(): void {
    this.guardando.set(true);
    this.errores.set({});
    this.error.set('');

    const datos = this.cotizacion()
      ? {
          fecha_tramite: this.fecha(),
          filas: this.filas().map((f) => ({ precio_unitario: f.precio_unitario ?? null, lleva_iva: !!f.lleva_iva }))
        }
      : {
          id_cat_egreso_unidad_administrativa: this.unidad(),
          fecha_tramite: this.fecha(),
          concepto: this.concepto(),
          mantenimiento_id: this.mantenimiento()?.id ?? null,
          filas: this.filas().map((f) => ({
            partida: f.partida,
            proyecto: f.proyecto,
            fuente: f.fuente,
            cantidad: f.cantidad,
            descripcion: f.descripcion
          }))
        };

    this.servicio.guardar(datos, this.id).subscribe({
      next: (r) => {
        const { id: nuevo, extemporanea } = r as { id?: number; extemporanea?: boolean };
        // Una extemporánea se avisa como advertencia (en SIEG, flash de error).
        this.router.navigate(['/requisiciones', this.id ?? nuevo], { state: { mensaje: r.message, tipo: extemporanea ? 'warning' : 'success' } });
      },
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(campos);
        this.error.set(mensaje || (Object.keys(campos).length ? 'Revisa los datos marcados.' : ''));
        this.guardando.set(false);
      }
    });
  }
}
