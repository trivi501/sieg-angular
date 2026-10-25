import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { RequisicionesService, abrirBlob } from '../requisiciones/requisiciones.service';
import { ContratoFormComponent } from './contrato-form.component';
import { ContratosService, DatosContrato, DetalleContrato, MesContrato, claseEstadoContrato, claseEstadoMes } from './contratos.service';

type Aviso = { tipo: 'success' | 'danger' | 'warning'; texto: string };

/**
 * Un contrato: sus datos, su autorización y sus mensualidades (una por mes de la vigencia: pendiente, vencida,
 * generada o pagada). Vigente, el área carga el XML del mes y se generan requisición, orden de compra y CLC.
 */
@Component({
  selector: 'app-contrato-detalle',
  imports: [FormsModule, RouterLink, DatePipe, AccionComponent, ContratoFormComponent],
  template: `
    @if (error()) {
      <div class="alert alert-danger">{{ error() }}</div>
    } @else if (d(); as d) {
      @let c = d.contrato;
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <a routerLink="/contratos" class="small text-muted">← Contratos</a>
          <h4 class="mb-1">
            Contrato {{ c.folio }}
            <span class="badge {{ clase(c.estado) }} align-middle">{{ c.estado_texto }}</span>
          </h4>
          <p class="text-muted small mb-0">
            {{ c.tipo_texto }}{{ c.numero_contrato ? ' · ' + c.numero_contrato : '' }} · {{ c.unidad }} · registró {{ c.capturo ?? '—' }}
          </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          @if (d.puede.editar && !editando()) {
            <button
              type="button"
              class="btn btn-outline-primary"
              (click)="editando.set(true)"
              appAccion="Editar contrato"
              icono="edit"
            ></button>
          }
          @if (d.puede.enviar) {
            <button
              type="button"
              class="btn btn-primary"
              [disabled]="ocupado()"
              (click)="accion('enviar')"
              appAccion="Enviar a autorizar"
              icono="send"
            ></button>
          }
          @if (d.puede.autorizar) {
            <button type="button" class="btn btn-success" [disabled]="ocupado()" (click)="accion('autorizar')">Autorizar contrato</button>
            <button type="button" class="btn btn-outline-danger" (click)="modo.set('rechazar')">No autorizar</button>
          }
          @if (d.puede.cancelar || d.puede.terminar) {
            <button
              type="button"
              class="btn btn-outline-danger"
              (click)="modo.set('cancelar')"
              [appAccion]="d.puede.terminar ? 'Terminar anticipadamente' : 'Cancelar contrato'"
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
      @if (c.observaciones) {
        <div class="alert py-2" [class.alert-danger]="c.estado === 'rechazado'" [class.alert-secondary]="c.estado !== 'rechazado'">
          <b>{{ c.estado === 'rechazado' ? 'No autorizado:' : 'Observaciones:' }}</b>
          {{ c.observaciones }}
        </div>
      }
      @if (c.impedimento_proveedor && ['captura', 'rechazado', 'por_autorizar', 'vigente'].includes(c.estado)) {
        <div class="alert alert-warning py-2">
          <b>Proveedor:</b>
          {{ c.impedimento_proveedor }}
        </div>
      }

      @if (modo(); as m) {
        <div class="card border-danger">
          <div class="card-body">
            <label class="form-label" for="texto">
              {{
                m === 'rechazar'
                  ? 'Observaciones para el área (qué debe corregir)'
                  : d.puede.terminar
                    ? 'Motivo de la terminación (ya no se generan más mensualidades)'
                    : 'Motivo de la cancelación'
              }}
            </label>
            <input id="texto" class="form-control mb-2" [(ngModel)]="texto" />
            <div class="d-flex gap-2">
              <button
                type="button"
                class="btn btn-danger"
                [disabled]="ocupado()"
                (click)="m === 'rechazar' ? accion('rechazar', { observaciones: texto }) : accion('cancelar', { motivo: texto })"
              >
                {{ m === 'rechazar' ? 'No autorizar' : d.puede.terminar ? 'Terminar contrato' : 'Cancelar contrato' }}
              </button>
              <button type="button" class="btn btn-outline-secondary" (click)="modo.set(null)">Cerrar</button>
            </div>
          </div>
        </div>
      }

      @if (editando()) {
        <div class="card">
          <div class="card-body">
            <app-contrato-form
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
              <span class="text-muted d-block">Proveedor</span>
              <b>{{ c.proveedor }}</b>
              {{ c.rfc ? '(' + c.rfc + ')' : '' }}
            </div>
            <div class="col-md-6">
              <span class="text-muted d-block">Concepto</span>
              {{ c.concepto }}
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Vigencia</span>
              {{ c.fecha_inicio | date: 'dd/MM/yyyy' }} al {{ c.fecha_fin | date: 'dd/MM/yyyy' }}
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Mensual</span>
              <b class="font-monospace">{{ money(c.monto_mensual) }}</b>
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Total del contrato</span>
              <b class="font-monospace">{{ money(c.monto_total) }}</b>
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Partida</span>
              {{ c.partida }} {{ c.nombre_partida ? '— ' + c.nombre_partida : '' }}
              <small class="d-block text-muted">
                Proy. {{ c.proyecto }} · fte. {{ c.fuente }}{{ c.disponible !== null ? ' · disponible ' + money(c.disponible) : '' }}
              </small>
            </div>
            @if (c.autorizado_at) {
              <div class="col-12 text-muted">
                Autorizado por {{ c.autorizado_por }} el {{ c.autorizado_at | date: 'dd/MM/yyyy HH:mm' }}.
              </div>
            }
          </div>
        </div>
      }

      <!-- Control de mensualidades -->
      @let r = d.resumen;
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-2">
          <div class="card mb-0">
            <div class="card-body py-2 small">
              <span class="text-muted d-block">Meses</span>
              <b class="fs-6">{{ r.meses }}</b>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-2">
          <div class="card mb-0">
            <div class="card-body py-2 small">
              <span class="text-muted d-block">Generadas</span>
              <b class="fs-6">{{ r.generadas }}</b>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-2">
          <div class="card mb-0">
            <div class="card-body py-2 small">
              <span class="text-muted d-block">Pagadas</span>
              <b class="fs-6">{{ r.pagadas }}</b>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-2">
          <div class="card mb-0">
            <div class="card-body py-2 small">
              <span class="text-muted d-block">Pendientes</span>
              <b class="fs-6" [class.text-danger]="r.vencidas > 0">{{ r.pendientes }}</b>
              {{ r.vencidas ? ' (' + r.vencidas + ' vencidas)' : '' }}
            </div>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="card mb-0">
            <div class="card-body py-2 small">
              <span class="text-muted d-block">Saldo por ejercer</span>
              <b class="fs-6 font-monospace">{{ money(r.saldo) }}</b>
              <small class="d-block text-muted">Ejercido {{ money(r.ejercido) }} · pagado {{ money(r.pagado) }}</small>
            </div>
          </div>
        </div>
      </div>

      @if (d.puede.facturar) {
        <div class="card border-primary">
          <div class="card-body row g-2 align-items-end">
            <div class="col-md-3">
              <label class="form-label small mb-0" for="periodo">Mensualidad</label>
              <select id="periodo" class="form-select form-select-sm sin-busqueda" [(ngModel)]="periodo">
                @for (m of porFacturar(d); track m.periodo) {
                  <option [value]="m.periodo">{{ m.nombre }}{{ m.estado === 'vencida' ? ' (vencida)' : '' }}</option>
                }
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small mb-0" for="xml">Factura del mes (XML)</label>
              <input
                id="xml"
                type="file"
                class="form-control form-control-sm"
                accept=".xml,text/xml,application/xml"
                (change)="archivo = $any($event.target).files?.[0] ?? null"
              />
            </div>
            <div class="col-md-3">
              <button
                type="button"
                class="btn btn-sm btn-primary w-100"
                [disabled]="ocupado() || !archivo || !periodo"
                (click)="facturar()"
              >
                {{ ocupado() ? 'Verificando y generando…' : 'Generar requisición, orden y CLC' }}
              </button>
            </div>
            <small class="col-12 text-muted">
              La factura debe ser del proveedor del contrato, estar vigente en el SAT y no rebasar el saldo por ejercer. Se valida la
              suficiencia de la partida al momento.
            </small>
          </div>
        </div>
      }

      <div class="card">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Mes</th>
                  <th>Estado</th>
                  <th>Factura</th>
                  <th class="text-end">Importe</th>
                  <th>Requisición / orden</th>
                  <th>CLC</th>
                  <th>Pago</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                @for (m of d.meses; track m.periodo) {
                  <tr>
                    <td class="fw-semibold text-nowrap">{{ m.nombre }}</td>
                    <td>
                      <span class="badge {{ claseMes(m.estado) }}">{{ textoMes[m.estado] }}</span>
                      @if (m.canceladas) {
                        <small class="d-block text-muted">{{ m.canceladas }} CLC cancelada(s)</small>
                      }
                    </td>
                    <td class="small">{{ m.factura ?? '—' }}</td>
                    <td class="text-end font-monospace">{{ m.importe !== null ? money(m.importe) : '—' }}</td>
                    <td class="small">
                      @if (m.requisicion_id) {
                        <a [routerLink]="['/requisiciones', m.requisicion_id]">{{ m.requisicion }}</a>
                        <span class="d-block text-muted">{{ m.orden }}</span>
                      }
                    </td>
                    <td class="small">
                      {{ m.clc ?? '' }}
                      <span class="d-block text-muted">{{ m.clc_estado }}</span>
                    </td>
                    <td class="small">
                      {{ m.pago ?? '' }}
                      <span class="d-block text-muted">{{ m.pago_fecha | date: 'dd/MM/yyyy' }}</span>
                    </td>
                    <td class="text-end text-nowrap">
                      @if (m.orden_id) {
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-secondary"
                          (click)="imprimirClc(m)"
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
export class ContratoDetalleComponent {
  private servicio = inject(ContratosService);
  private requisiciones = inject(RequisicionesService);

  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));
  readonly money = money;
  readonly clase = claseEstadoContrato;
  readonly claseMes = claseEstadoMes;
  readonly textoMes: Record<string, string> = {
    pendiente: 'Pendiente',
    vencida: 'Vencida sin facturar',
    generada: 'CLC en trámite',
    pagada: 'Pagada'
  };

  d = signal<DetalleContrato | null>(null);
  error = signal('');
  aviso = signal<Aviso | null>(history.state?.mensaje ? { tipo: 'success', texto: history.state.mensaje } : null);
  ocupado = signal(false);
  editando = signal(false);
  errores = signal<Record<string, string>>({});
  modo = signal<'rechazar' | 'cancelar' | null>(null);
  texto = '';
  periodo = '';
  archivo: File | null = null;

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.detalle(this.id).subscribe({
      next: (d) => {
        this.d.set(d);
        this.periodo = d.siguiente ?? '';
      },
      error: (e: HttpErrorResponse) => this.error.set(e.status === 404 ? 'No existe.' : erroresDe(e).mensaje)
    });
  }

  porFacturar(d: DetalleContrato): MesContrato[] {
    return d.meses.filter((m) => m.estado === 'pendiente' || m.estado === 'vencida');
  }

  datosEdicion(d: DetalleContrato): DatosContrato {
    const c = d.contrato;
    return {
      tipo: c.tipo,
      numero_contrato: c.numero_contrato ?? '',
      id_cat_egreso_unidad_administrativa: c.id_unidad,
      proveedor_id: c.proveedor_id,
      concepto: c.concepto,
      fecha_inicio: c.fecha_inicio,
      fecha_fin: c.fecha_fin,
      monto_mensual: c.monto_mensual,
      monto_total: c.monto_total,
      fuente: c.fuente,
      proyecto: c.proyecto,
      partida: c.partida
    };
  }

  guardar(datos: DatosContrato): void {
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

  accion(accion: 'enviar' | 'autorizar' | 'rechazar' | 'cancelar', datos: object = {}): void {
    this.ocupado.set(true);
    this.servicio.accion(this.id, accion, datos).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.modo.set(null);
        this.texto = '';
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

  facturar(): void {
    if (!this.archivo || !this.periodo) return;
    this.ocupado.set(true);
    this.aviso.set(null);
    this.servicio.facturar(this.id, this.periodo, this.archivo).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.archivo = null;
        this.aviso.set({ tipo: r.aviso ? 'warning' : 'success', texto: r.message + (r.aviso ? ' ' + r.aviso : '') });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.aviso.set({ tipo: 'danger', texto: Object.values(campos)[0] ?? mensaje });
      }
    });
  }

  imprimirClc(m: MesContrato): void {
    this.requisiciones.pdfClc(m.orden_id!).subscribe((b) => abrirBlob(b, `${m.clc}.pdf`));
  }
}
