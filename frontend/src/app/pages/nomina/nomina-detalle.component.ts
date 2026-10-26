import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { RequisicionesService, abrirBlob } from '../requisiciones/requisiciones.service';
import { LineaPresupuesto, opcionesPresupuesto } from '../contratos/contratos.service';
import { NominaFormComponent } from './nomina-form.component';
import { DatosNomina, DatosRenglon, DetalleNomina, NominaService, OpcionesNomina, RenglonNomina, claseEstadoNomina } from './nomina.service';

type Aviso = { tipo: 'success' | 'danger' | 'warning'; texto: string };

/**
 * Un trámite de nómina: su encabezado, sus conceptos (importados del Excel o capturados a mano), la suficiencia de
 * cada partida y, ya validado, la CLC de cada unidad administrativa.
 */
@Component({
  selector: 'app-nomina-detalle',
  imports: [FormsModule, RouterLink, DatePipe, AccionComponent, SelectBusquedaDirective, NominaFormComponent],
  template: `
    @if (error()) {
      <div class="alert alert-danger">{{ error() }}</div>
    } @else if (d(); as d) {
      @let n = d.nomina;
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <a routerLink="/nomina" class="small text-muted">← Nómina</a>
          <h4 class="mb-1">
            {{ n.tipo_texto }} {{ n.folio }}
            <span class="badge {{ clase(n.estado) }} align-middle">{{ n.estado_texto }}</span>
          </h4>
          <p class="text-muted small mb-0">
            {{ n.periodo ?? n.referencia ?? '' }} · capturó {{ n.capturo ?? '—' }}
            @if (n.validado_at) {
              · validó {{ n.validado_por }} el {{ n.validado_at | date: 'dd/MM/yyyy HH:mm' }}
            }
          </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          @if (d.puede.editar && !editando()) {
            <button type="button" class="btn btn-outline-primary" (click)="editando.set(true)" appAccion="Editar datos" icono="edit"></button>
          }
          @if (d.puede.enviar) {
            <button
              type="button"
              class="btn btn-primary"
              [disabled]="ocupado()"
              (click)="accion('enviar')"
              appAccion="Enviar a validar"
              icono="send"
            ></button>
          }
          @if (d.puede.validar) {
            <button type="button" class="btn btn-success" [disabled]="ocupado() || !alcanza(d)" (click)="accion('validar')">
              {{ ocupado() ? 'Generando CLC…' : 'Validar y generar CLC' }}
            </button>
            <button type="button" class="btn btn-outline-danger" (click)="modo.set('rechazar')">Regresar a RH</button>
          }
          @if (d.puede.cancelar) {
            <button
              type="button"
              class="btn btn-outline-danger"
              (click)="modo.set('cancelar')"
              appAccion="Cancelar"
              icono="stop"
            ></button>
          }
        </div>
      </div>

      @if (aviso(); as a) {
        <div class="alert alert-{{ a.tipo }} alert-dismissible py-2">
          {{ a.texto }}
          <button type="button" class="btn-close" (click)="aviso.set(null)"></button>
        </div>
      }
      @if (n.observaciones) {
        <div class="alert py-2" [class.alert-danger]="n.estado === 'rechazado'" [class.alert-secondary]="n.estado !== 'rechazado'">
          <b>{{ n.estado === 'rechazado' ? 'Observaciones de Control Presupuestal:' : 'Observaciones:' }}</b>
          {{ n.observaciones }}
        </div>
      }

      @if (modo(); as m) {
        <div class="card border-danger">
          <div class="card-body">
            <label class="form-label" for="texto">
              {{ m === 'rechazar' ? 'Observaciones para Recursos Humanos (qué debe corregir)' : 'Motivo de la cancelación' }}
            </label>
            <input id="texto" class="form-control mb-2" [(ngModel)]="texto" />
            <div class="d-flex gap-2">
              <button
                type="button"
                class="btn btn-danger"
                [disabled]="ocupado() || !texto.trim()"
                (click)="m === 'rechazar' ? accion('rechazar', { observaciones: texto }) : accion('cancelar', { motivo: texto })"
              >
                {{ m === 'rechazar' ? 'Regresar a Recursos Humanos' : 'Cancelar trámite' }}
              </button>
              <button type="button" class="btn btn-outline-secondary" (click)="modo.set(null)">Cerrar</button>
            </div>
          </div>
        </div>
      }

      @if (editando()) {
        <div class="card">
          <div class="card-body">
            <app-nomina-form
              [inicial]="datosEdicion(d)"
              [errores]="errores()"
              [guardando]="ocupado()"
              textoGuardar="Guardar cambios"
              (guardar)="guardar($event)"
              (cerrar)="editando.set(false)"
            />
          </div>
        </div>
      } @else {
        <div class="card">
          <div class="card-body row g-3 small">
            <div class="col-md-5">
              <span class="text-muted d-block">Concepto</span>
              <b>{{ n.concepto }}</b>
            </div>
            <div class="col-md-4">
              <span class="text-muted d-block">A favor de (CLC sin factura)</span>
              {{ n.a_favor_de }}
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Fecha de pago</span>
              {{ (n.fecha_pago | date: 'dd/MM/yyyy') ?? '—' }}
            </div>
            <div class="col-6 col-md-5">
              <span class="text-muted d-block">{{ n.individual ? 'Oficio o expediente' : 'Referencia' }}</span>
              {{ n.referencia ?? '—' }}
            </div>
            <div class="col-6 col-md-4">
              <span class="text-muted d-block">Archivo importado</span>
              @if (n.archivo) {
                <a href="" (click)="$event.preventDefault(); descargarArchivo(n.archivo)">{{ n.archivo }}</a>
              } @else {
                —
              }
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Total</span>
              <b class="fs-6 font-monospace">{{ money(n.total) }}</b>
            </div>
          </div>
        </div>
      }

      <!-- CLC generadas (una por unidad administrativa) -->
      @if (d.clc.length) {
        <div class="card border-success">
          <div class="card-header py-2"><b>CLC generadas</b> <small class="text-muted">— una por unidad administrativa</small></div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Unidad</th>
                    <th>Requisición / registro</th>
                    <th class="text-end">Importe</th>
                    <th>CLC</th>
                    <th>Pago</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  @for (c of d.clc; track c.orden_id) {
                    <tr>
                      <td class="small">{{ c.unidad }}</td>
                      <td class="small">
                        <a [routerLink]="['/requisiciones', c.requisicion_id]">{{ c.requisicion }}</a>
                        <span class="d-block text-muted">{{ c.registro }}</span>
                      </td>
                      <td class="text-end font-monospace">{{ money(c.importe) }}</td>
                      <td class="small">
                        {{ c.clc }}
                        <span class="d-block text-muted">{{ c.clc_estado }}</span>
                      </td>
                      <td class="small">{{ (c.pago_fecha | date: 'dd/MM/yyyy') ?? '—' }}</td>
                      <td class="text-end">
                        @if (c.clc) {
                          <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            (click)="imprimirClc(c.orden_id, c.clc)"
                            appAccion="Imprimir CLC"
                            icono="file-done"
                          ></button>
                        }
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          </div>
        </div>
      }

      <!-- Importar el Excel y capturar conceptos (mientras se captura) -->
      @if (d.puede.editar) {
        <div class="card border-primary">
          <div class="card-body">
            @if (!n.individual) {
              <div class="row g-2 align-items-end mb-3">
                <div class="col-md-7">
                  <label class="form-label small mb-0" for="archivo">Excel de la nómina (plantilla: Unidad, Fuente, Proyecto, Partida, Concepto, Importe)</label>
                  <input
                    id="archivo"
                    type="file"
                    class="form-control form-control-sm"
                    accept=".xlsx,.xls,.csv"
                    (change)="archivo = $any($event.target).files?.[0] ?? null"
                  />
                </div>
                <div class="col-md-3">
                  <button type="button" class="btn btn-sm btn-primary w-100" [disabled]="ocupado() || !archivo" (click)="importar()">
                    {{ importando() ? 'Importando…' : 'Importar (reemplaza los conceptos)' }}
                  </button>
                </div>
                <div class="col-md-2">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary w-100"
                    (click)="plantilla()"
                    appAccion="Plantilla"
                    icono="file-excel"
                    [conNombre]="true"
                  ></button>
                </div>
              </div>
            }

            <b class="small d-block mb-2">{{ n.individual ? 'Conceptos del pago' : 'Agregar un concepto a mano' }}</b>
            @if (opciones(); as o) {
              @let op = presupuesto();
              <div class="row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label small mb-0" for="unidad">Unidad administrativa</label>
                  <select id="unidad" class="form-select form-select-sm" [ngModel]="renglon.id_cat_egreso_unidad_administrativa" (ngModelChange)="cambiarUnidad($event)">
                    <option [ngValue]="null">Seleccionar…</option>
                    @for (u of o.unidades; track u.id) {
                      <option [ngValue]="u.id">{{ u.clave }} — {{ u.nombre }}</option>
                    }
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label small mb-0" for="partida">Partida</label>
                  <select id="partida" class="form-select form-select-sm" [ngModel]="renglon.partida" (ngModelChange)="cambiarPartida($event)" [disabled]="!lineas().length">
                    <option value="">{{ lineas().length ? 'Partida…' : 'Elige la unidad' }}</option>
                    @for (p of op.partidas; track p.clave) {
                      <option [value]="p.clave">{{ p.clave }} — {{ p.nombre }}</option>
                    }
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label small mb-0" for="proyecto">Proyecto</label>
                  <select id="proyecto" class="form-select form-select-sm" [ngModel]="renglon.proyecto" (ngModelChange)="cambiarProyecto($event)" [disabled]="!renglon.partida">
                    <option value="">Proyecto…</option>
                    @for (p of op.proyectos; track p.clave) {
                      <option [value]="p.clave">{{ p.clave }} — {{ p.nombre }}</option>
                    }
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label small mb-0" for="fuente">Fuente</label>
                  <select id="fuente" class="form-select form-select-sm" [(ngModel)]="renglon.fuente" [disabled]="!renglon.proyecto">
                    <option value="">Fuente…</option>
                    @for (f of op.fuentes; track f.clave) {
                      <option [value]="f.clave">{{ f.clave }} — {{ f.nombre }} ({{ money(f.disponible) }})</option>
                    }
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small mb-0" for="concepto-r">Concepto</label>
                  <input
                    id="concepto-r"
                    class="form-control form-control-sm"
                    maxlength="300"
                    [placeholder]="n.individual ? 'P. ej. Indemnización, parte proporcional de aguinaldo' : 'P. ej. Sueldos base'"
                    [(ngModel)]="renglon.concepto"
                  />
                </div>
                <div class="col-md-3">
                  <label class="form-label small mb-0" for="importe-r">Importe</label>
                  <input id="importe-r" type="number" step="0.01" min="0" class="form-control form-control-sm" [(ngModel)]="renglon.importe" />
                </div>
                <div class="col-md-3">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary w-100"
                    [disabled]="ocupado() || !renglon.fuente || !renglon.concepto.trim() || !renglon.importe"
                    (click)="agregar()"
                    appAccion="Agregar concepto"
                    icono="plus"
                    [conNombre]="true"
                  ></button>
                </div>
              </div>
            }
          </div>
        </div>
      }

      <!-- Suficiencia por partida (antes de validar) -->
      @if (d.suficiencia.length) {
        <div class="card" [class.border-danger]="!alcanza(d)">
          <div class="card-header py-2">
            <b>Suficiencia presupuestal</b>
            @if (alcanza(d)) {
              <span class="badge bg-light-success text-success ms-2">Todas las partidas alcanzan</span>
            } @else {
              <span class="badge bg-light-danger text-danger ms-2">Hay partidas sin suficiencia: se requiere una modificación presupuestal</span>
            }
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Unidad</th>
                    <th>Partida</th>
                    <th>Proy. / fte.</th>
                    <th class="text-end">Requerido</th>
                    <th class="text-end">Disponible</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  @for (s of d.suficiencia; track s.unidad_clave + s.fuente + s.proyecto + s.partida) {
                    <tr>
                      <td>{{ s.unidad_clave }}</td>
                      <td class="small">{{ s.partida }} {{ s.nombre_partida ? '— ' + s.nombre_partida : '' }}</td>
                      <td class="small text-muted">{{ s.proyecto }} · {{ s.fuente }}</td>
                      <td class="text-end font-monospace">{{ money(s.requerido) }}</td>
                      <td class="text-end font-monospace">{{ money(s.disponible) }}</td>
                      <td>
                        <span class="badge" [class]="s.suficiente ? 'bg-light-success text-success' : 'bg-light-danger text-danger'">
                          {{ s.suficiente ? 'Alcanza' : 'No alcanza' }}
                        </span>
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          </div>
        </div>
      }

      <div class="card">
        <div class="card-header py-2"><b>Conceptos</b> <small class="text-muted">({{ d.renglones.length }})</small></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Unidad</th>
                  <th>Fte. / proy. / partida</th>
                  <th>Concepto</th>
                  <th class="text-end">Importe</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                @for (r of d.renglones; track r.id) {
                  <tr>
                    <td class="small">
                      <b>{{ r.unidad_clave }}</b>
                      <span class="d-block text-muted text-truncate" style="max-width: 220px">{{ r.unidad }}</span>
                    </td>
                    <td class="small text-nowrap">{{ r.fuente }} · {{ r.proyecto }} · <b>{{ r.partida }}</b></td>
                    <td class="small">
                      {{ r.concepto }}
                      @if (r.fila) {
                        <span class="text-muted">(fila {{ r.fila }})</span>
                      }
                    </td>
                    <td class="text-end font-monospace">{{ money(r.importe) }}</td>
                    <td class="text-end">
                      @if (d.puede.editar) {
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-danger"
                          [disabled]="ocupado()"
                          (click)="quitar(r)"
                          appAccion="Quitar"
                          icono="delete"
                        ></button>
                      }
                    </td>
                  </tr>
                } @empty {
                  <tr>
                    <td colspan="5" class="text-center text-muted py-3">
                      {{ n.individual ? 'Agrega los conceptos del pago.' : 'Importa el Excel de la nómina.' }}
                    </td>
                  </tr>
                }
              </tbody>
              @if (d.renglones.length) {
                <tfoot>
                  <tr>
                    <th colspan="3" class="text-end">Total</th>
                    <th class="text-end font-monospace">{{ money(n.total) }}</th>
                    <th></th>
                  </tr>
                </tfoot>
              }
            </table>
          </div>
        </div>
      </div>
    } @else {
      <p class="text-muted">Cargando…</p>
    }
  `,
  styles: `
    td,
    th {
      font-size: 13px;
    }
  `
})
export class NominaDetalleComponent {
  private servicio = inject(NominaService);
  private requisiciones = inject(RequisicionesService);

  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));
  readonly money = money;
  readonly clase = claseEstadoNomina;

  d = signal<DetalleNomina | null>(null);
  error = signal('');
  aviso = signal<Aviso | null>(history.state?.mensaje ? { tipo: 'success', texto: history.state.mensaje } : null);
  ocupado = signal(false);
  importando = signal(false);
  editando = signal(false);
  errores = signal<Record<string, string>>({});
  modo = signal<'rechazar' | 'cancelar' | null>(null);
  opciones = signal<OpcionesNomina | null>(null);
  lineas = signal<LineaPresupuesto[]>([]);
  texto = '';
  archivo: File | null = null;
  renglon: DatosRenglon = this.renglonVacio();

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.detalle(this.id).subscribe({
      next: (d) => {
        this.d.set(d);
        if (d.puede.editar && !this.opciones()) this.servicio.opciones().subscribe((o) => this.opciones.set(o));
      },
      error: (e: HttpErrorResponse) => this.error.set(e.status === 404 ? 'No existe.' : erroresDe(e).mensaje)
    });
  }

  alcanza(d: DetalleNomina): boolean {
    return d.suficiencia.every((s) => s.suficiente);
  }

  datosEdicion(d: DetalleNomina): DatosNomina {
    const n = d.nomina;
    return {
      tipo: n.tipo,
      periodo: n.periodo ?? '',
      fecha_pago: n.fecha_pago ?? '',
      concepto: n.concepto,
      beneficiario: n.beneficiario,
      beneficiario_numero: n.beneficiario_numero ?? '',
      referencia: n.referencia ?? ''
    };
  }

  /** Partidas en orden de su clave (las del capítulo 1000 primero), luego sus proyectos y fuentes. */
  presupuesto() {
    const op = opcionesPresupuesto(this.lineas(), this.renglon.partida, this.renglon.proyecto);
    return { ...op, partidas: [...op.partidas].sort((a, b) => a.clave.localeCompare(b.clave)) };
  }

  cambiarUnidad(id: number | null): void {
    this.renglon = { ...this.renglon, id_cat_egreso_unidad_administrativa: id, partida: '', proyecto: '', fuente: '' };
    this.lineas.set([]);
    if (id) this.servicio.opciones(id).subscribe((o) => this.lineas.set(o.lineas));
  }

  cambiarPartida(valor: string): void {
    this.renglon = { ...this.renglon, partida: valor, proyecto: '', fuente: '' };
    const ps = this.presupuesto().proyectos;
    if (ps.length === 1) this.cambiarProyecto(ps[0].clave);
  }

  cambiarProyecto(valor: string): void {
    this.renglon = { ...this.renglon, proyecto: valor, fuente: '' };
    const fs = this.presupuesto().fuentes;
    if (fs.length === 1) this.renglon.fuente = fs[0].clave;
  }

  guardar(datos: DatosNomina): void {
    this.ocupado.set(true);
    this.errores.set({});
    this.servicio.actualizar(this.id, datos).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.editando.set(false);
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(campos);
        if (mensaje) this.aviso.set({ tipo: 'danger', texto: mensaje });
      }
    });
  }

  accion(accion: 'enviar' | 'validar' | 'rechazar' | 'cancelar', datos: object = {}): void {
    this.ocupado.set(true);
    this.servicio.accion(this.id, accion, datos).subscribe(this.respuesta(() => {
      this.modo.set(null);
      this.texto = '';
    }));
  }

  importar(): void {
    if (!this.archivo) return;
    this.ocupado.set(true);
    this.importando.set(true);
    this.servicio
      .importar(this.id, this.archivo)
      .pipe(finalize(() => this.importando.set(false)))
      .subscribe(this.respuesta(() => (this.archivo = null)));
  }

  agregar(): void {
    this.ocupado.set(true);
    this.servicio.agregarRenglon(this.id, this.renglon).subscribe(
      this.respuesta(() => (this.renglon = { ...this.renglonVacio(), id_cat_egreso_unidad_administrativa: this.renglon.id_cat_egreso_unidad_administrativa }))
    );
  }

  quitar(r: RenglonNomina): void {
    this.ocupado.set(true);
    this.servicio.quitarRenglon(this.id, r.id).subscribe(this.respuesta());
  }

  plantilla(): void {
    this.servicio.plantilla().subscribe((b) => abrirBlob(b, 'plantilla_nomina.xlsx', true));
  }

  descargarArchivo(nombre: string): void {
    this.servicio.archivo(this.id).subscribe((b) => abrirBlob(b, nombre, true));
  }

  imprimirClc(orden: number, clc: string): void {
    this.requisiciones.pdfClc(orden).subscribe((b) => abrirBlob(b, `${clc}.pdf`));
  }

  private respuesta(despues: () => void = () => undefined) {
    return {
      next: (r: { message: string }) => {
        this.ocupado.set(false);
        despues();
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.aviso.set({ tipo: 'danger', texto: Object.values(campos)[0] ?? mensaje });
      }
    };
  }

  private renglonVacio(): DatosRenglon {
    return { id_cat_egreso_unidad_administrativa: null, fuente: '', proyecto: '', partida: '', concepto: '', importe: null };
  }
}
