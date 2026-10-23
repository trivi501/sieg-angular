import { Component, TemplateRef, computed, inject, signal, viewChild } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { NgbModal, NgbModalRef } from '@ng-bootstrap/ng-bootstrap';

import { Paginado, erroresDe } from 'src/app/core/api';
import { PaginacionComponent } from '../admin/paginacion.component';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { HistorialVehiculoComponent } from '../vehiculos/historial-vehiculo.component';
import {
  CampoCatalogo,
  CatalogosService,
  DefinicionCatalogo,
  Movimiento,
  RegistroCatalogo,
  ResumenImportacion,
  Valor
} from './catalogos.service';

type Mensaje = { tipo: 'success' | 'danger'; texto: string };

/**
 * Pantalla genérica de cualquier catálogo de App\Catalogos\Catalogos (SIEG: pages/catalogos/Catalogo.tsx).
 * Columnas, formulario, validación, baja lógica, historial e importación salen de la definición del backend.
 */
@Component({
  selector: 'app-catalogo',
  imports: [SelectBusquedaDirective, AccionComponent, FormsModule, RouterLink, PaginacionComponent, DatePipe],
  templateUrl: './catalogo.component.html'
})
export class CatalogoComponent {
  private servicio = inject(CatalogosService);
  private modal = inject(NgbModal);

  readonly slug = inject(ActivatedRoute).snapshot.paramMap.get('slug') ?? '';

  private tplFormulario = viewChild.required<TemplateRef<unknown>>('formulario');
  private tplHistorial = viewChild.required<TemplateRef<unknown>>('historialTpl');
  private tplImportar = viewChild.required<TemplateRef<unknown>>('importarTpl');

  definicion = signal<DefinicionCatalogo | null>(null);
  pagina = signal<Paginado<RegistroCatalogo> | null>(null);
  error = signal('');
  mensaje = signal<Mensaje | null>(null);
  buscar = '';
  estado = '';

  columnas = computed(() => this.definicion()?.campos.filter((c) => c.enLista) ?? []);
  editables = computed(() => this.definicion()?.campos.filter((c) => c.editable) ?? []);

  // Formulario
  editando = signal<RegistroCatalogo | null>(null);
  valores: Record<string, Valor> = {};
  errores = signal<Record<string, string>>({});
  guardando = signal(false);
  private ventana?: NgbModalRef;

  // Historial
  historial = signal<{ registro: RegistroCatalogo; movimientos: Movimiento[] | null } | null>(null);

  // Importación
  archivo: File | null = null;
  resumen = signal<ResumenImportacion | null>(null);
  importando = signal(false);
  errorImportacion = signal('');

  constructor() {
    this.servicio.definicion(this.slug).subscribe({
      next: (d) => {
        this.definicion.set(d);
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) =>
        this.error.set(e.status === 404 ? 'El catálogo no existe.' : e.status === 403 ? 'No tienes permiso para ver este catálogo.' : erroresDe(e).mensaje)
    });
  }

  cargar(page: number): void {
    this.servicio.registros(this.slug, page, this.buscar, this.estado).subscribe((p) => this.pagina.set(p));
  }

  // --- Alta y edición ---

  abrir(registro: RegistroCatalogo | null): void {
    this.editando.set(registro);
    this.errores.set({});
    this.valores = {};
    for (const campo of this.editables()) {
      const actual = registro?.valores[campo.nombre];
      this.valores[campo.nombre] = registro ? (actual ?? (campo.tipo === 'booleano' ? false : null)) : this.porDefecto(campo);
    }
    this.ventana = this.modal.open(this.tplFormulario(), { size: 'xl', scrollable: true });
  }

  private porDefecto(campo: CampoCatalogo): Valor {
    if (campo.porDefecto !== null && campo.porDefecto !== undefined) {
      return campo.tipo === 'seleccion' || campo.tipo === 'relacion' ? String(campo.porDefecto) : campo.porDefecto;
    }
    return campo.tipo === 'booleano' ? false : null;
  }

  /** Los select trabajan con texto; las opciones del backend vienen como texto. */
  valorSelect(campo: CampoCatalogo): string {
    const v = this.valores[campo.nombre];
    return v === null || v === undefined ? '' : String(v);
  }

  guardar(): void {
    const registro = this.editando();
    this.guardando.set(true);
    this.errores.set({});

    this.servicio.guardar(this.slug, this.valores, registro?.id).subscribe({
      next: (r) => {
        this.guardando.set(false);
        this.ventana?.close();
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar(this.pagina()?.current_page ?? 1);
      },
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
        this.guardando.set(false);
      }
    });
  }

  // --- Baja y reactivación ---

  cambiarEstado(registro: RegistroCatalogo, activo: boolean): void {
    const singular = this.definicion()?.singular ?? 'registro';
    const pregunta = activo
      ? `¿Reactivar este ${singular}?`
      : `¿Dar de baja este ${singular}? No se borra: deja de ofrecerse en las capturas y se puede reactivar.`;
    if (!confirm(pregunta)) {
      return;
    }
    this.servicio.cambiarEstado(this.slug, registro.id, activo).subscribe({
      next: (r) => {
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar(this.pagina()?.current_page ?? 1);
      },
      error: (e: HttpErrorResponse) => this.mensaje.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }

  // --- Padrón vehicular: mantenimientos y su costo ---

  verMantenimientos(registro: RegistroCatalogo): void {
    HistorialVehiculoComponent.abrir(this.modal, Number(registro.id));
  }

  // --- Historial ---

  verHistorial(registro: RegistroCatalogo): void {
    this.historial.set({ registro, movimientos: null });
    this.modal.open(this.tplHistorial(), { size: 'lg', scrollable: true });
    this.servicio.historial(this.slug, registro.id).subscribe((m) => this.historial.set({ registro, movimientos: m }));
  }

  /** Campos que cambiaron en un movimiento, con su etiqueta. */
  cambios(m: Movimiento): { campo: string; antes: unknown; despues: unknown }[] {
    const nombres = new Set([...Object.keys(m.antes ?? {}), ...Object.keys(m.despues ?? {})]);
    const etiquetas = new Map(this.definicion()?.campos.map((c) => [c.nombre, c.etiqueta]));
    return [...nombres].map((n) => ({ campo: etiquetas.get(n) ?? n, antes: m.antes?.[n] ?? null, despues: m.despues?.[n] ?? null }));
  }

  // --- Importación ---

  abrirImportar(): void {
    this.archivo = null;
    this.resumen.set(null);
    this.errorImportacion.set('');
    this.modal.open(this.tplImportar(), { size: 'lg', scrollable: true });
  }

  descargarPlantilla(): void {
    this.servicio.plantilla(this.slug).subscribe((blob) => {
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `plantilla_${this.slug}.xlsx`;
      a.click();
      URL.revokeObjectURL(url);
    });
  }

  elegirArchivo(evento: Event): void {
    this.archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    this.resumen.set(null);
  }

  analizar(): void {
    if (!this.archivo) {
      return;
    }
    this.importando.set(true);
    this.errorImportacion.set('');
    this.servicio.previa(this.slug, this.archivo).subscribe({
      next: (r) => {
        this.resumen.set(r);
        this.importando.set(false);
      },
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errorImportacion.set(campos['archivo'] ?? mensaje);
        this.importando.set(false);
      }
    });
  }

  confirmarImportacion(ventana: NgbModalRef): void {
    const resumen = this.resumen();
    if (!resumen) {
      return;
    }
    this.importando.set(true);
    this.servicio.importar(this.slug, resumen.token).subscribe({
      next: (r) => {
        this.importando.set(false);
        ventana.close();
        this.mensaje.set({ tipo: 'success', texto: r.message });
        this.cargar(1);
      },
      error: (e: HttpErrorResponse) => {
        this.errorImportacion.set(erroresDe(e).mensaje);
        this.importando.set(false);
      }
    });
  }

  texto(valor: unknown): string {
    if (valor === null || valor === undefined || valor === '') {
      return '—';
    }
    if (typeof valor === 'boolean') {
      return valor ? 'Sí' : 'No';
    }
    return String(valor);
  }
}
