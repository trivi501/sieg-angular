import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { forkJoin, of } from 'rxjs';
import { catchError } from 'rxjs/operators';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { RequisicionesService, abrirBlob } from '../requisiciones/requisiciones.service';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { DetalleTramite, FacturaTramite, LineaTramite, RUTA_TIPO, ServiciosService, TipoTramite, claseEstadoTramite } from './servicios.service';

type Aviso = { tipo: 'success' | 'danger' | 'warning'; texto: string };
/** Clasificación de una factura mientras se edita. */
type Clasificacion = { partida: string; proyecto: string; fuente: string; concepto: string };
/** Fondo revolvente: partida / proyecto / fuente de un artículo. */
type LineaArticulo = { partida: string; proyecto: string; fuente: string };

/**
 * Un trámite de servicios: subir facturas (XML), ver su contenido, clasificar cada una (partida / proyecto /
 * fuente y concepto), enviarlo a validar; Recursos Materiales lo valida o lo regresa con observaciones; ya
 * validado, se imprimen requisición, orden de compra y CLC.
 */
@Component({
  selector: 'app-servicio-detalle',
  imports: [FormsModule, RouterLink, DatePipe, AccionComponent, SelectBusquedaDirective],
  templateUrl: './servicio-detalle.component.html',
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
    .uuid {
      font-size: 11px;
      word-break: break-all;
    }
  `
})
export class ServicioDetalleComponent {
  private servicio = inject(ServiciosService);
  private requisiciones = inject(RequisicionesService);
  private router = inject(Router);

  private readonly ruta = inject(ActivatedRoute).snapshot;
  readonly id = Number(this.ruta.paramMap.get('id'));
  /** servicio (/servicios) o fondo revolvente (/fondos). */
  readonly tipo: TipoTramite = this.ruta.data['tipo'] ?? 'servicio';
  readonly fondo = this.tipo === 'fondo';
  readonly base = '/' + RUTA_TIPO[this.tipo];
  readonly money = money;
  readonly clase = claseEstadoTramite;

  d = signal<DetalleTramite | null>(null);
  error = signal('');
  aviso = signal<Aviso | null>(history.state?.mensaje ? { tipo: 'success', texto: history.state.mensaje } : null);
  ocupado = signal(false);

  /** Clasificación en edición de cada factura (por id). */
  clasif: Record<number, Clasificacion> = {};
  /** Fondo: clasificación en edición de cada artículo de cada factura. */
  articulos: Record<number, LineaArticulo[]> = {};
  trabajador = { nombre: '', numero: '', puesto: '' };
  erroresFactura = signal<Record<number, string>>({});
  /** Facturas con el contenido (artículos) desplegado. */
  abiertas = signal<Set<number>>(new Set());

  concepto = '';
  observaciones = '';
  motivo = '';
  modo = signal<'rechazar' | 'cancelar' | null>(null);

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.detalle(this.tipo, this.id).subscribe({
      next: (d) => {
        this.d.set(d);
        this.concepto = d.tramite.concepto;
        this.trabajador = { nombre: d.tramite.trabajador_nombre ?? '', numero: d.tramite.trabajador_numero ?? '', puesto: d.tramite.trabajador_puesto ?? '' };
        this.articulos = Object.fromEntries(
          d.facturas.map((f) => [f.id, f.conceptos.map((a) => ({ partida: a.partida ?? '', proyecto: a.proyecto ?? '', fuente: a.fuente ?? '' }))])
        );
        this.clasif = Object.fromEntries(
          d.facturas.map((f) => [f.id, { partida: f.partida ?? '', proyecto: f.proyecto ?? '', fuente: f.fuente ?? '', concepto: f.concepto ?? '' }])
        );
      },
      error: (e: HttpErrorResponse) => this.error.set(e.status === 404 ? 'No existe.' : erroresDe(e).mensaje)
    });
  }

  // --- Facturas ---

  subir(evento: Event): void {
    const input = evento.target as HTMLInputElement;
    const archivos = Array.from(input.files ?? []);
    if (!archivos.length) return;
    this.ocupado.set(true);
    this.aviso.set(null);
    // Una por una (cada una se verifica en el SAT); se informa el resultado de todas.
    forkJoin(
      archivos.map((a) =>
        this.servicio.subirFactura(this.tipo, this.id, a).pipe(
          catchError((e: HttpErrorResponse) => {
            const { campos, mensaje } = erroresDe(e);
            return of({ message: `${a.name}: ${campos['xml'] ?? mensaje}`, error: true, aviso: null });
          })
        )
      )
    ).subscribe((rs) => {
      this.ocupado.set(false);
      input.value = '';
      const errores = rs.filter((r) => 'error' in r);
      const aviso = rs.map((r) => r.aviso).find((a) => !!a);
      this.aviso.set({
        tipo: errores.length ? 'danger' : aviso ? 'warning' : 'success',
        texto: [...rs.map((r) => r.message), ...(aviso ? [aviso] : [])].join(' · ')
      });
      this.cargar();
    });
  }

  alternar(id: number): void {
    const s = new Set(this.abiertas());
    if (s.has(id)) s.delete(id);
    else s.add(id);
    this.abiertas.set(s);
  }

  /** Opciones encadenadas: partida → proyecto → fuente (de las líneas de la secretaría). */
  partidas(): { clave: string; nombre: string | null }[] {
    return this.unicas(this.d()?.lineas ?? [], 'partida', 'nombre_partida');
  }
  proyectos(f: FacturaTramite): { clave: string; nombre: string | null }[] {
    const c = this.clasif[f.id];
    return this.unicas((this.d()?.lineas ?? []).filter((l) => l.partida === c?.partida), 'proyecto', 'nombre_proyecto');
  }
  fuentes(f: FacturaTramite): { clave: string; nombre: string | null; disponible: number }[] {
    const c = this.clasif[f.id];
    return (this.d()?.lineas ?? [])
      .filter((l) => l.partida === c?.partida && l.proyecto === c?.proyecto)
      .map((l) => ({ clave: l.fuente, nombre: l.nombre_fuente, disponible: l.disponible }));
  }
  private unicas(lineas: LineaTramite[], campo: 'partida' | 'proyecto', nombre: 'nombre_partida' | 'nombre_proyecto') {
    const vistos = new Map<string, string | null>();
    for (const l of lineas) if (!vistos.has(l[campo])) vistos.set(l[campo], l[nombre]);
    return [...vistos].map(([clave, n]) => ({ clave, nombre: n }));
  }
  cambiarPartida(f: FacturaTramite, valor: string): void {
    this.clasif[f.id] = { ...this.clasif[f.id], partida: valor, proyecto: '', fuente: '' };
  }
  cambiarProyecto(f: FacturaTramite, valor: string): void {
    this.clasif[f.id] = { ...this.clasif[f.id], proyecto: valor, fuente: '' };
  }

  /** Fondo: opciones encadenadas de un artículo. */
  proyectosDe(partida: string): { clave: string; nombre: string | null }[] {
    return this.unicas((this.d()?.lineas ?? []).filter((l) => l.partida === partida), 'proyecto', 'nombre_proyecto');
  }
  fuentesDe(partida: string, proyecto: string): { clave: string; nombre: string | null; disponible: number }[] {
    return (this.d()?.lineas ?? [])
      .filter((l) => l.partida === partida && l.proyecto === proyecto)
      .map((l) => ({ clave: l.fuente, nombre: l.nombre_fuente, disponible: l.disponible }));
  }
  cambiarArticulo(f: FacturaTramite, i: number, campo: keyof LineaArticulo, valor: string): void {
    const a = { ...this.articulos[f.id][i], [campo]: valor };
    if (campo === 'partida') {
      a.proyecto = '';
      a.fuente = '';
    }
    if (campo === 'proyecto') a.fuente = '';
    // Con una sola opción, se elige sola.
    if (a.partida && !a.proyecto) {
      const ps = this.proyectosDe(a.partida);
      if (ps.length === 1) a.proyecto = ps[0].clave;
    }
    if (a.partida && a.proyecto && !a.fuente) {
      const fs = this.fuentesDe(a.partida, a.proyecto);
      if (fs.length === 1) a.fuente = fs[0].clave;
    }
    this.articulos[f.id] = this.articulos[f.id].map((x, j) => (j === i ? a : x));
  }
  /** Fondo: aplica la clasificación del primer artículo a todos los de la factura. */
  igualATodos(f: FacturaTramite): void {
    const primero = this.articulos[f.id][0];
    this.articulos[f.id] = this.articulos[f.id].map(() => ({ ...primero }));
  }

  guardarClasificacion(f: FacturaTramite): void {
    const c = this.clasif[f.id];
    const datos = this.fondo
      ? { concepto: c.concepto || null, articulos: this.articulos[f.id].map((a) => ({ partida: a.partida || null, proyecto: a.proyecto || null, fuente: a.fuente || null })) }
      : { partida: c.partida || null, proyecto: c.proyecto || null, fuente: c.fuente || null, concepto: c.concepto || null };
    this.servicio.clasificar(this.tipo, this.id, f.id, datos).subscribe({
      next: () => {
        this.erroresFactura.set({ ...this.erroresFactura(), [f.id]: '' });
        this.aviso.set({ tipo: 'success', texto: `Factura ${f.serie_folio ?? f.uuid} clasificada.` });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.erroresFactura.set({ ...this.erroresFactura(), [f.id]: Object.values(campos)[0] ?? mensaje });
      }
    });
  }

  quitar(f: FacturaTramite): void {
    if (!confirm(`¿Quitar la factura ${f.serie_folio ?? f.uuid} del trámite?`)) return;
    this.servicio.quitarFactura(this.tipo, this.id, f.id).subscribe({
      next: (r) => {
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => this.aviso.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }

  xml(f: FacturaTramite): void {
    this.servicio.descargarXml(this.tipo, this.id, f.id).subscribe((b) => abrirBlob(b, `${f.uuid}.xml`, true));
  }

  guardarConcepto(): void {
    const datos = {
      concepto: this.concepto,
      ...(this.fondo ? { trabajador_nombre: this.trabajador.nombre || null, trabajador_numero: this.trabajador.numero || null, trabajador_puesto: this.trabajador.puesto || null } : {})
    };
    this.servicio.actualizar(this.tipo, this.id, datos).subscribe({
      next: (r) => this.aviso.set({ tipo: 'success', texto: r.message }),
      error: (e: HttpErrorResponse) => this.aviso.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }

  // --- Trámite ---

  ejecutar(accion: 'enviar' | 'validar' | 'rechazar' | 'cancelar'): void {
    const genera = this.fondo ? 'la requisición (con su suficiencia) y la CLC a favor del trabajador' : 'la requisición (con su suficiencia), la orden de compra y la CLC';
    if (accion === 'validar' && !confirm(`Al validarlo se generan ${genera}. ¿Validar?`)) return;
    const datos = accion === 'rechazar' ? { observaciones: this.observaciones } : accion === 'cancelar' ? { motivo: this.motivo } : {};
    this.ocupado.set(true);
    this.servicio.accion(this.tipo, this.id, accion, datos).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.modo.set(null);
        this.observaciones = '';
        this.motivo = '';
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.aviso.set({ tipo: 'danger', texto: Object.values(campos)[0] ?? mensaje });
      }
    });
  }

  // --- Documentos (ya validado) ---

  imprimirRequisicion(): void {
    const t = this.d()!.tramite;
    this.requisiciones.pdf(t.requisicion_id!).subscribe((b) => abrirBlob(b, `FUR-${t.requisicion}.pdf`));
  }
  imprimirOrden(): void {
    const o = this.d()!.tramite.orden!;
    this.requisiciones.pdfOrden(o.id).subscribe((b) => abrirBlob(b, `${o.folio}.pdf`));
  }
  imprimirClc(): void {
    const o = this.d()!.tramite.orden!;
    this.requisiciones.pdfClc(o.id).subscribe((b) => abrirBlob(b, `${o.clc}.pdf`));
  }

  irALista(): void {
    this.router.navigate([this.base]);
  }
}
