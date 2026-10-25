import { Component, TemplateRef, computed, inject, signal, viewChild } from '@angular/core';
import { DatePipe, KeyValuePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { NgbModal, NgbModalRef } from '@ng-bootstrap/ng-bootstrap';

import { AuthService } from 'src/app/core/auth/auth.service';
import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { BANDEJAS, Clc, ClcService, Grupo, TramiteClc, claseEstadoClc } from './clc.service';
import { CuentaTesoreria, TesoreriaService } from '../tesoreria/tesoreria.service';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

/**
 * Trámite de CLC (SIEG: pages/clc/Index.tsx): bandejas por área para recibir, enviar (relación),
 * devolver y pagar; seguimiento y relaciones. Las reglas las vuelve a revisar el backend (TramiteClc).
 */
@Component({
  selector: 'app-clc',
  imports: [SelectBusquedaDirective, AccionComponent, FormsModule, RouterLink, DatePipe, KeyValuePipe],
  templateUrl: './clc.component.html',
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
    .concepto {
      max-width: 240px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  `
})
export class ClcComponent {
  private servicio = inject(ClcService);
  private tesoreria = inject(TesoreriaService);
  private auth = inject(AuthService);
  private modal = inject(NgbModal);

  private tplAccion = viewChild.required<TemplateRef<unknown>>('accionTpl');

  readonly money = money;
  readonly claseEstado = claseEstadoClc;
  /** keyvalue sin reordenar: estados y formas de pago en el orden del trámite. */
  readonly sinOrden = () => 0;
  readonly hoy = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' });

  datos = signal<TramiteClc | null>(null);
  mensaje = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);
  ultimaRelacion = signal<{ id: number; folio: string } | null>(null);

  /** Control Presupuestal y Admin ven todas las bandejas; cada área, la suya. Actuar requiere el permiso. */
  bandejas = computed(() => {
    const d = this.datos();
    return d ? BANDEJAS.filter((b) => d.veTodo || b.permisos.some((p) => this.auth.can(p))) : [];
  });

  pestanas = computed(() => [
    ...this.bandejas().map((b) => ({ clave: b.clave, titulo: b.titulo })),
    { clave: 'seguimiento', titulo: 'Seguimiento' },
    ...(this.datos()?.verRelaciones ? [{ clave: 'relaciones', titulo: 'Relaciones' }] : [])
  ]);

  pestana = signal('');
  bandeja = computed(() => this.bandejas().find((b) => b.clave === this.pestana()));

  /** Selección por grupo (título del grupo → ids). */
  seleccion = signal<Record<string, number[]>>({});
  buscar = signal('');
  filtroEstado = signal('');
  /** Reporte de CLC en Excel (pestaña Seguimiento). */
  excelDesde = '';
  excelHasta = '';
  excelCanceladas = false;
  exportando = signal(false);

  // Diálogo de acción
  accion = signal<{ clave: string; grupo: Grupo; ids: number[] } | null>(null);
  observaciones = '';
  pagoFecha = '';
  pagoForma = 'transferencia';
  pagoReferencia = '';
  /** Pago: cuenta del Ayuntamiento de la que sale (Tesorería agrupa por proveedor). */
  cuentaId: number | null = null;
  cuentas = signal<CuentaTesoreria[]>([]);
  errores = signal<Record<string, string>>({});
  ocupado = signal(false);

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.tramite().subscribe((d) => {
      this.datos.set(d);
      if (!this.pestana() || !this.pestanas().some((p) => p.clave === this.pestana())) {
        this.pestana.set(this.pestanas()[0]?.clave ?? 'seguimiento');
      }
    });
  }

  private coincide(c: Clc): boolean {
    const texto = this.buscar().trim().toLowerCase();
    return !texto || `${c.clc} ${c.orden} ${c.requisicion} ${c.proveedor} ${c.unidad} ${c.concepto}`.toLowerCase().includes(texto);
  }

  clcsDe(grupo: Grupo): Clc[] {
    const d = this.datos();
    // "Mi área": solo las de su secretaría (Admin y Control Presupuestal, todas).
    const soloPropias = this.pestana() === 'area' && !d?.veTodo;
    return (d?.clcs ?? []).filter((c) => grupo.estados.includes(c.estado) && (!soloPropias || c.seguimiento) && this.coincide(c));
  }

  seguimiento = computed(() =>
    (this.datos()?.clcs ?? []).filter((c) => c.seguimiento && (!this.filtroEstado() || c.estado === this.filtroEstado()) && this.coincide(c))
  );

  totalBandeja(clave: string): number {
    const b = BANDEJAS.find((x) => x.clave === clave);
    return b ? b.grupos.reduce((t, g) => t + this.clcsDe(g).length, 0) : 0;
  }

  // --- Selección ---

  elegidos(grupo: Grupo): number[] {
    const visibles = new Set(this.clcsDe(grupo).map((c) => c.id));
    return (this.seleccion()[grupo.titulo] ?? []).filter((id) => visibles.has(id));
  }

  marcado(grupo: Grupo, id: number): boolean {
    return (this.seleccion()[grupo.titulo] ?? []).includes(id);
  }

  alternar(grupo: Grupo, id: number, valor: boolean): void {
    this.seleccion.update((s) => {
      const actuales = s[grupo.titulo] ?? [];
      return { ...s, [grupo.titulo]: valor ? [...actuales, id] : actuales.filter((x) => x !== id) };
    });
  }

  alternarTodas(grupo: Grupo, valor: boolean): void {
    this.seleccion.update((s) => ({ ...s, [grupo.titulo]: valor ? this.clcsDe(grupo).map((c) => c.id) : [] }));
  }

  todasMarcadas(grupo: Grupo): boolean {
    const clcs = this.clcsDe(grupo);
    return clcs.length > 0 && clcs.every((c) => this.marcado(grupo, c.id));
  }

  puede(clave: string): boolean {
    const a = this.datos()?.acciones[clave];
    return !!a && this.auth.can(a.permiso);
  }

  totalSeleccion(grupo: Grupo): number {
    const ids = new Set(this.elegidos(grupo));
    return this.clcsDe(grupo)
      .filter((c) => ids.has(c.id))
      .reduce((t, c) => t + c.neto, 0);
  }

  // --- Acciones ---

  abrir(clave: string, grupo: Grupo): void {
    this.accion.set({ clave, grupo, ids: this.elegidos(grupo) });
    this.observaciones = '';
    this.pagoFecha = this.hoy;
    this.pagoForma = 'transferencia';
    this.pagoReferencia = '';
    this.errores.set({});
    if (this.datos()?.acciones[clave]?.pago) {
      this.tesoreria.cuentas().subscribe((c) => {
        this.cuentas.set(c);
        this.cuentaId = c.length === 1 ? c[0].id : this.cuentaId;
      });
    }
    this.modal.open(this.tplAccion(), { size: 'md' });
  }

  ejecutar(ventana: NgbModalRef): void {
    const a = this.accion();
    const config = a ? this.datos()?.acciones[a.clave] : null;
    if (!a || !config) {
      return;
    }
    this.ocupado.set(true);
    this.errores.set({});
    this.servicio
      .accion({
        accion: a.clave,
        ordenes: a.ids,
        observaciones: this.observaciones || undefined,
        ...(config.pago ? { pago_fecha: this.pagoFecha, pago_forma: this.pagoForma, pago_referencia: this.pagoReferencia, cuenta_id: this.cuentaId } : {})
      })
      .subscribe({
        next: (r) => {
          this.ocupado.set(false);
          ventana.close();
          this.mensaje.set({ tipo: 'success', texto: r.message });
          this.ultimaRelacion.set(r.relacion);
          this.seleccion.update((s) => ({ ...s, [a.grupo.titulo]: [] }));
          this.cargar();
        },
        error: (e: HttpErrorResponse) => {
          this.ocupado.set(false);
          const { campos, mensaje } = erroresDe(e);
          this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
        }
      });
  }

  // --- Excel ---

  exportarExcel(): void {
    this.exportando.set(true);
    this.servicio
      .excel({ estado: this.filtroEstado(), buscar: this.buscar().trim(), desde: this.excelDesde, hasta: this.excelHasta, canceladas: this.excelCanceladas ? 1 : '' })
      .subscribe({
        next: (b) => {
          this.exportando.set(false);
          abrirBlob(b, 'clc.xlsx', true);
        },
        error: () => {
          this.exportando.set(false);
          this.mensaje.set({ tipo: 'danger', texto: 'No se pudo generar el Excel de CLC.' });
        }
      });
  }

  // --- PDF ---

  relacionPdf(id: number, folio: string): void {
    this.servicio.relacionPdf(id).subscribe((b) => abrirBlob(b, `${folio}.pdf`));
  }

  clcPdf(c: Clc): void {
    this.servicio.clcPdf(c.id).subscribe({
      next: (b) => abrirBlob(b, `${c.clc}.pdf`),
      error: () => this.mensaje.set({ tipo: 'danger', texto: `No se pudo abrir la CLC ${c.clc}.` })
    });
  }
}
