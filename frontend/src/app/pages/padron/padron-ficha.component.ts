import { Component, inject, signal } from '@angular/core';
import { DatePipe, KeyValuePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { AccionComponent } from 'src/app/shared/accion.component';
import { abrirBlob } from '../requisiciones/requisiciones.service';
import { PadronDatosComponent } from './padron-datos.component';
import { DatosPadron, DocumentoPadron, FichaPadron, PadronService, claseEstadoPadron } from './padron.service';

type Aviso = { tipo: 'success' | 'danger'; texto: string };

/**
 * Ficha del proveedor en el padrón: datos, requisitos del expediente, documentos (con su vigencia; la opinión del SAT,
 * con su sentido), emisión y renovación de la cédula (PDF) y su historial; rechazo, suspensión y reactivación.
 */
@Component({
  selector: 'app-padron-ficha',
  imports: [FormsModule, RouterLink, DatePipe, KeyValuePipe, AccionComponent, PadronDatosComponent],
  template: `
    @if (error()) {
      <div class="alert alert-danger">{{ error() }}</div>
    } @else if (d(); as d) {
      @let p = d.proveedor;
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <a routerLink="/padron" class="small text-muted">← Padrón de proveedores</a>
          <h4 class="mb-1">
            {{ p.nombre }}
            <span class="badge {{ clase(p.estado) }} align-middle">{{ p.estado_texto }}</span>
          </h4>
          <p class="text-muted small mb-0">
            {{ p.rfc }} · {{ p.tipo_persona === 'moral' ? 'Persona moral' : p.tipo_persona === 'fisica' ? 'Persona física' : '—' }}
            {{ p.cedula ? '· cédula ' + p.cedula + ' vigente hasta el ' + (p.vigencia | date: 'dd/MM/yyyy') : '' }}
          </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          @if (d.puede.cedula_pdf) {
            <button type="button" class="btn btn-outline-secondary" (click)="pdf()" appAccion="Imprimir cédula" icono="printer"></button>
          }
          @if (d.puede.editar && !editando()) {
            <button
              type="button"
              class="btn btn-outline-primary"
              (click)="editando.set(true)"
              appAccion="Editar datos"
              icono="edit"
            ></button>
          }
          @if (d.puede.rechazar) {
            <button type="button" class="btn btn-outline-danger" (click)="modo.set('rechazar')">Rechazar</button>
          }
          @if (d.puede.suspender) {
            <button type="button" class="btn btn-outline-danger" (click)="modo.set('suspender')">Suspender</button>
          }
          @if (d.puede.reactivar) {
            <button type="button" class="btn btn-outline-success" [disabled]="ocupado()" (click)="estado('reactivar')">Reactivar</button>
          }
        </div>
      </div>

      @if (aviso(); as a) {
        <div class="alert alert-{{ a.tipo }} alert-dismissible py-2">
          {{ a.texto }}
          <button type="button" class="btn-close" (click)="aviso.set(null)"></button>
        </div>
      }
      @if (p.impedimento) {
        <div class="alert alert-danger py-2">
          <b>No se le pueden asignar órdenes de compra:</b>
          {{ p.impedimento }}
        </div>
      } @else if (p.observaciones) {
        <div class="alert alert-secondary py-2">
          <b>Observaciones:</b>
          {{ p.observaciones }}
        </div>
      }

      @if (modo(); as m) {
        <div class="card border-danger">
          <div class="card-body">
            <label class="form-label" for="motivo">
              Motivo {{ m === 'suspender' ? 'de la suspensión (no se le asignarán órdenes de compra)' : 'del rechazo' }}
            </label>
            <input id="motivo" class="form-control mb-2" [(ngModel)]="motivo" />
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-danger" [disabled]="ocupado()" (click)="estado(m)">
                {{ m === 'suspender' ? 'Suspender' : 'Rechazar' }}
              </button>
              <button type="button" class="btn btn-outline-secondary" (click)="modo.set(null)">Cerrar</button>
            </div>
          </div>
        </div>
      }

      @if (editando()) {
        <div class="card">
          <div class="card-body">
            <app-padron-datos
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
            <div class="col-md-6">
              <span class="text-muted d-block">Giro o actividad</span>
              {{ p.giro ?? '—' }}
            </div>
            <div class="col-md-6">
              <span class="text-muted d-block">Representante legal</span>
              {{ p.representante_legal ?? '—' }}
            </div>
            <div class="col-md-4">
              <span class="text-muted d-block">Régimen fiscal</span>
              {{ p.regimen_fiscal ?? '—' }}
            </div>
            <div class="col-md-4">
              <span class="text-muted d-block">Correo / teléfono</span>
              {{ p.correo ?? '—' }} {{ p.telefono ? '· ' + p.telefono : '' }}
            </div>
            <div class="col-md-4">
              <span class="text-muted d-block">CLABE</span>
              {{ p.clabe ?? '—' }}
            </div>
            <div class="col-12">
              <span class="text-muted d-block">Domicilio fiscal</span>
              {{ p.domicilio ?? '—' }}
            </div>
          </div>
        </div>
      }

      @if (!p.compra_unica) {
        <div class="row g-3">
          <!-- Requisitos y cédula -->
          <div class="col-lg-5">
            <div class="card h-100 mb-0">
              <div class="card-body">
                <h6>Requisitos para la cédula</h6>
                <ul class="list-unstyled small mb-3">
                  @for (r of d.requisitos; track r.tipo) {
                    <li [class.text-success]="r.cumplido" [class.text-danger]="!r.cumplido && r.obligatorio">
                      {{ r.cumplido ? '✔' : '✘' }} {{ r.nombre }}
                    </li>
                  }
                </ul>
                @if (d.faltantes.length) {
                  <div class="alert alert-warning py-2 small mb-2">
                    @for (f of d.faltantes; track $index) {
                      <div>{{ f }}</div>
                    }
                  </div>
                }
                @if (d.puede.editar) {
                  <input
                    class="form-control form-control-sm mb-2"
                    maxlength="1000"
                    placeholder="Observaciones de la cédula (opcional)"
                    [(ngModel)]="obsCedula"
                  />
                  <button type="button" class="btn btn-success w-100" [disabled]="!d.puede.emitir || ocupado()" (click)="emitir()">
                    {{ d.cedulas.length ? 'Renovar cédula (un año)' : 'Emitir cédula (un año)' }}
                  </button>
                }
                @if (d.cedulas.length) {
                  <h6 class="mt-3">Cédulas</h6>
                  <table class="table table-sm mb-0">
                    <tbody>
                      @for (c of d.cedulas; track c.id) {
                        <tr>
                          <td class="fw-semibold">{{ c.numero }}</td>
                          <td class="small">{{ c.fecha_emision | date: 'dd/MM/yyyy' }} al {{ c.vigencia | date: 'dd/MM/yyyy' }}</td>
                          <td class="small text-muted">{{ c.emitio }}</td>
                        </tr>
                      }
                    </tbody>
                  </table>
                }
              </div>
            </div>
          </div>

          <!-- Expediente -->
          <div class="col-lg-7">
            <div class="card h-100 mb-0">
              <div class="card-body">
                <h6>Expediente</h6>
                @if (d.puede.documentos) {
                  <div class="row g-2 align-items-end mb-3 small">
                    <div class="col-md-6">
                      <label class="form-label small mb-0" for="tipo">Documento</label>
                      <select id="tipo" class="form-select form-select-sm sin-busqueda" [(ngModel)]="doc.tipo">
                        @for (t of d.tipos_documento | keyvalue: sinOrden; track t.key) {
                          <option [value]="t.key">{{ t.value }}</option>
                        }
                      </select>
                    </div>
                    <div class="col-6 col-md-3">
                      <label class="form-label small mb-0" for="fecha">Fecha</label>
                      <input id="fecha" type="date" class="form-control form-control-sm" [max]="hoy" [(ngModel)]="doc.fecha_documento" />
                    </div>
                    <div class="col-6 col-md-3">
                      <label class="form-label small mb-0" for="hasta">Vigente hasta</label>
                      <input id="hasta" type="date" class="form-control form-control-sm" [(ngModel)]="doc.vigente_hasta" />
                    </div>
                    @if (doc.tipo === 'opinion_cumplimiento') {
                      <div class="col-md-4">
                        <label class="form-label small mb-0" for="sentido">Sentido</label>
                        <select id="sentido" class="form-select form-select-sm sin-busqueda" [(ngModel)]="doc.sentido">
                          <option value="">Seleccionar…</option>
                          <option value="positiva">Positiva</option>
                          <option value="negativa">Negativa</option>
                        </select>
                      </div>
                    }
                    <div class="col">
                      <label class="form-label small mb-0" for="archivo">Archivo (PDF o imagen)</label>
                      <input
                        id="archivo"
                        type="file"
                        class="form-control form-control-sm"
                        accept=".pdf,.jpg,.jpeg,.png"
                        (change)="archivo = $any($event.target).files?.[0] ?? null"
                      />
                    </div>
                    <div class="col-auto">
                      <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        [disabled]="ocupado() || !archivo || !doc.fecha_documento"
                        (click)="subir()"
                      >
                        Agregar
                      </button>
                    </div>
                    @if (doc.tipo === 'opinion_cumplimiento') {
                      <small class="col-12 text-muted">
                        Si no capturas "vigente hasta", la opinión del SAT vale 30 días desde su fecha.
                      </small>
                    }
                  </div>
                }
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Documento</th>
                        <th>Fecha</th>
                        <th>Vigencia</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      @for (x of d.documentos; track x.id) {
                        <tr>
                          <td class="small">
                            {{ x.tipo_texto }}
                            @if (x.sentido) {
                              <span
                                class="badge ms-1"
                                [class]="x.sentido === 'positiva' ? 'bg-light-success text-success' : 'bg-light-danger text-danger'"
                              >
                                {{ x.sentido }}
                              </span>
                            }
                            <span class="d-block text-muted">{{ x.nombre_archivo }}</span>
                          </td>
                          <td class="small text-nowrap">{{ x.fecha_documento | date: 'dd/MM/yyyy' }}</td>
                          <td class="small text-nowrap" [class.text-danger]="!x.vigente">
                            {{ x.vigente_hasta ? (x.vigente_hasta | date: 'dd/MM/yyyy') : '—' }}{{ x.vigente ? '' : ' (vencido)' }}
                          </td>
                          <td class="text-end text-nowrap">
                            <button
                              type="button"
                              class="btn btn-sm btn-outline-secondary"
                              (click)="descargar(x)"
                              appAccion="Ver documento"
                              icono="download"
                            ></button>
                            @if (d.puede.documentos) {
                              <button
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                (click)="quitar(x)"
                                appAccion="Quitar documento"
                                icono="delete"
                              ></button>
                            }
                          </td>
                        </tr>
                      } @empty {
                        <tr>
                          <td colspan="4" class="text-center text-muted py-3">Sin documentos.</td>
                        </tr>
                      }
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      } @else {
        <div class="alert alert-info">Proveedor de compra única (eventual): no lleva cédula ni expediente del padrón.</div>
      }
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
export class PadronFichaComponent {
  private servicio = inject(PadronService);

  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));
  readonly clase = claseEstadoPadron;
  readonly sinOrden = () => 0;
  readonly hoy = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' });

  d = signal<FichaPadron | null>(null);
  error = signal('');
  aviso = signal<Aviso | null>(history.state?.mensaje ? { tipo: 'success', texto: history.state.mensaje } : null);
  ocupado = signal(false);
  editando = signal(false);
  errores = signal<Record<string, string>>({});
  modo = signal<'rechazar' | 'suspender' | null>(null);
  motivo = '';
  obsCedula = '';
  archivo: File | null = null;
  doc = { tipo: 'constancia_fiscal', fecha_documento: this.hoy, vigente_hasta: '', sentido: '' };

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.ficha(this.id).subscribe({
      next: (d) => this.d.set(d),
      error: (e: HttpErrorResponse) => this.error.set(e.status === 404 ? 'No existe.' : erroresDe(e).mensaje)
    });
  }

  datosEdicion(d: FichaPadron): DatosPadron {
    const p = d.proveedor;
    return {
      nombre: p.nombre,
      rfc: p.rfc ?? '',
      tipo_persona: p.tipo_persona,
      representante_legal: p.representante_legal ?? '',
      giro: p.giro ?? '',
      regimen_fiscal: p.regimen_fiscal ?? '',
      correo: p.correo ?? '',
      telefono: p.telefono ?? '',
      domicilio: p.domicilio ?? '',
      clabe: p.clabe ?? '',
      compra_unica: p.compra_unica
    };
  }

  guardar(datos: DatosPadron): void {
    this.ocupado.set(true);
    this.errores.set({});
    this.servicio.actualizar(this.id, datos).subscribe({
      next: (r) => this.listo(r.message, () => this.editando.set(false)),
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        this.errores.set(erroresDe(e).campos);
      }
    });
  }

  subir(): void {
    if (!this.archivo) return;
    this.ocupado.set(true);
    this.servicio.subirDocumento(this.id, this.doc, this.archivo).subscribe({
      next: (r) =>
        this.listo(r.message, () => {
          this.archivo = null;
          this.doc = { ...this.doc, vigente_hasta: '', sentido: '' };
        }),
      error: (e: HttpErrorResponse) => this.fallo(e)
    });
  }

  descargar(x: DocumentoPadron): void {
    this.servicio.descargarDocumento(this.id, x.id).subscribe((b) => abrirBlob(b, x.nombre_archivo));
  }

  quitar(x: DocumentoPadron): void {
    if (!confirm(`¿Quitar "${x.tipo_texto}" del expediente?`)) return;
    this.servicio
      .quitarDocumento(this.id, x.id)
      .subscribe({ next: (r) => this.listo(r.message), error: (e: HttpErrorResponse) => this.fallo(e) });
  }

  emitir(): void {
    this.ocupado.set(true);
    this.servicio.emitirCedula(this.id, this.obsCedula).subscribe({
      next: (r) => this.listo(r.message, () => (this.obsCedula = '')),
      error: (e: HttpErrorResponse) => this.fallo(e)
    });
  }

  estado(accion: 'rechazar' | 'suspender' | 'reactivar'): void {
    this.ocupado.set(true);
    this.servicio.estado(this.id, accion, this.motivo).subscribe({
      next: (r) =>
        this.listo(r.message, () => {
          this.modo.set(null);
          this.motivo = '';
        }),
      error: (e: HttpErrorResponse) => this.fallo(e)
    });
  }

  pdf(): void {
    this.servicio.cedulaPdf(this.id).subscribe((b) => abrirBlob(b, `cedula_${this.d()?.proveedor.rfc}.pdf`));
  }

  private listo(mensaje: string, despues?: () => void): void {
    this.ocupado.set(false);
    despues?.();
    this.aviso.set({ tipo: 'success', texto: mensaje });
    this.cargar();
  }

  private fallo(e: HttpErrorResponse): void {
    this.ocupado.set(false);
    const { campos, mensaje } = erroresDe(e);
    this.aviso.set({ tipo: 'danger', texto: Object.values(campos)[0] ?? mensaje });
  }
}
