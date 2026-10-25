import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { DetallePago, Movimiento, TesoreriaService, claseEstadoPago } from './tesoreria.service';

/**
 * Un pago de Tesorería: sus CLC y su conciliación con el estado de cuenta (se sugieren los cargos con su número o su
 * monto; si el monto no cuadra no se cierra). Cheque: entregarlo. Se cancela mientras no esté conciliado.
 */
@Component({
  selector: 'app-tesoreria-pago',
  imports: [FormsModule, RouterLink, DatePipe, AccionComponent],
  template: `
    @if (error()) {
      <div class="alert alert-danger">{{ error() }}</div>
    } @else if (d(); as d) {
      @let p = d.pago;
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <a routerLink="/tesoreria/pagos" class="small text-muted">← Pagos</a>
          <h4 class="mb-1">
            Pago {{ p.numero }}
            <span class="badge {{ clase(p.estado) }} align-middle">{{ p.estado_texto }}</span>
          </h4>
          <p class="text-muted small mb-0">{{ p.beneficiario }} · {{ p.fecha | date: 'dd/MM/yyyy' }} · registró {{ p.capturo ?? '—' }}</p>
        </div>
        <div class="d-flex gap-2">
          @if (d.puede.entregar) {
            <button type="button" class="btn btn-outline-primary" (click)="modo.set('entregar')">Entregar cheque</button>
          }
          @if (d.puede.desconciliar) {
            <button type="button" class="btn btn-outline-secondary" (click)="accion('desconciliar')" [disabled]="ocupado()">
              Quitar conciliación
            </button>
          }
          @if (d.puede.cancelar) {
            <button
              type="button"
              class="btn btn-outline-danger"
              (click)="modo.set('cancelar')"
              appAccion="Cancelar pago"
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

      @if (modo(); as m) {
        <div class="card" [class.border-danger]="m === 'cancelar'">
          <div class="card-body">
            @if (m === 'cancelar') {
              <label class="form-label" for="motivo">
                Motivo de la cancelación (sus CLC regresan a la mesa de control para pagarse de nuevo)
              </label>
              <input id="motivo" class="form-control mb-2" [(ngModel)]="texto" />
            } @else {
              <label class="form-label" for="entregado">¿A quién se entregó el cheque?</label>
              <input id="entregado" class="form-control mb-2" maxlength="300" [(ngModel)]="texto" />
            }
            <div class="d-flex gap-2">
              <button
                type="button"
                class="btn"
                [class.btn-danger]="m === 'cancelar'"
                [class.btn-primary]="m !== 'cancelar'"
                [disabled]="ocupado()"
                (click)="confirmar(m)"
              >
                {{ m === 'cancelar' ? 'Cancelar pago' : 'Registrar entrega' }}
              </button>
              <button type="button" class="btn btn-outline-secondary" (click)="modo.set(null)">Cerrar</button>
            </div>
          </div>
        </div>
      }

      <div class="card">
        <div class="card-body row g-3 small">
          <div class="col-6 col-md-3">
            <span class="text-muted d-block">Importe</span>
            <b class="font-monospace fs-6">{{ money(p.importe) }}</b>
          </div>
          <div class="col-6 col-md-3">
            <span class="text-muted d-block">Forma</span>
            {{ p.forma_texto }}{{ p.cheque_numero ? ' No. ' + p.cheque_numero : '' }}
          </div>
          <div class="col-md-6">
            <span class="text-muted d-block">Sale de</span>
            {{ p.cuenta }}{{ p.fuente ? ' · fuente ' + p.fuente : '' }}
          </div>
          @if (p.cuenta_destino) {
            <div class="col-md-6">
              <span class="text-muted d-block">Cuenta del beneficiario</span>
              {{ p.cuenta_destino }}
            </div>
          }
          @if (p.entregado_at) {
            <div class="col-md-6">
              <span class="text-muted d-block">Entregado</span>
              {{ p.entregado_at | date: 'dd/MM/yyyy HH:mm' }} a {{ p.entregado_a }}
            </div>
          }
          @if (p.motivo_cancelacion) {
            <div class="col-12 text-danger">
              <b>Cancelado:</b>
              {{ p.motivo_cancelacion }}
            </div>
          }
          @if (p.observaciones) {
            <div class="col-12">
              <span class="text-muted d-block">Observaciones</span>
              {{ p.observaciones }}
            </div>
          }
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h6>CLC del pago ({{ d.ordenes.length }})</h6>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>CLC</th>
                  <th>Orden</th>
                  <th>Requisición</th>
                  <th>SAT</th>
                  <th class="text-end">Pagado</th>
                </tr>
              </thead>
              <tbody>
                @for (o of d.ordenes; track o.id) {
                  <tr>
                    <td class="fw-semibold">{{ o.clc }}</td>
                    <td>{{ o.orden }}</td>
                    <td>
                      @if (o.requisicion_id) {
                        <a [routerLink]="['/requisiciones', o.requisicion_id]">{{ o.requisicion }}</a>
                      }
                    </td>
                    <td>{{ o.sat_estado ?? '—' }}</td>
                    <td class="text-end font-monospace">{{ money(o.importe) }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Conciliación -->
      <div class="card">
        <div class="card-body">
          <h6>Conciliación con el estado de cuenta</h6>
          @if (d.movimiento; as mv) {
            <p class="small mb-0">
              <span class="badge bg-light-success text-success me-1">Conciliado</span>
              Cargo del {{ mv.fecha | date: 'dd/MM/yyyy' }} por
              <b class="font-monospace">{{ money(mv.importe) }}</b>
              · {{ mv.referencia }} {{ mv.clave_rastreo }} {{ mv.concepto }} · {{ p.conciliado_at | date: 'dd/MM/yyyy HH:mm' }}
            </p>
          } @else if (p.estado === 'cancelado') {
            <p class="small text-muted mb-0">Pago cancelado.</p>
          } @else {
            <p class="small text-muted">
              Cargos sin conciliar de la cuenta con el número del pago ({{ p.numero
              }}{{ p.cheque_numero ? ' o el cheque ' + p.cheque_numero : '' }}) o con el mismo monto. Si el monto no cuadra, no se cierra.
            </p>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Referencia / rastreo</th>
                    <th>Concepto</th>
                    <th class="text-end">Cargo</th>
                    <th class="text-end">Diferencia</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  @for (mv of d.candidatos; track mv.id) {
                    <tr>
                      <td>{{ mv.fecha | date: 'dd/MM/yyyy' }}</td>
                      <td class="small">
                        {{ mv.referencia }}
                        <span class="d-block text-muted">{{ mv.clave_rastreo }}</span>
                      </td>
                      <td class="small">{{ mv.concepto }}</td>
                      <td class="text-end font-monospace">{{ money(mv.importe) }}</td>
                      <td class="text-end font-monospace" [class.text-danger]="diferencia(mv) !== 0">{{ money(diferencia(mv)) }}</td>
                      <td class="text-end">
                        @if (d.puede.conciliar) {
                          <button type="button" class="btn btn-sm btn-outline-success" [disabled]="ocupado()" (click)="conciliar(mv)">
                            Conciliar
                          </button>
                        }
                      </td>
                    </tr>
                  } @empty {
                    <tr>
                      <td colspan="6" class="text-center text-muted py-3">
                        No hay cargos de la cuenta que coincidan. Regístralos en
                        <a routerLink="/tesoreria/movimientos">Movimientos y conciliación</a>
                        .
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }
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
export class TesoreriaPagoComponent {
  private servicio = inject(TesoreriaService);

  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));
  readonly money = money;
  readonly clase = claseEstadoPago;

  d = signal<DetallePago | null>(null);
  error = signal('');
  aviso = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(
    history.state?.mensaje ? { tipo: 'success', texto: history.state.mensaje } : null
  );
  ocupado = signal(false);
  modo = signal<'cancelar' | 'entregar' | null>(null);
  texto = '';

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.pago(this.id).subscribe({
      next: (d) => this.d.set(d),
      error: (e: HttpErrorResponse) => this.error.set(e.status === 404 ? 'No existe.' : erroresDe(e).mensaje)
    });
  }

  diferencia(mv: Movimiento): number {
    return Math.round((mv.importe - (this.d()?.pago.importe ?? 0)) * 100) / 100;
  }

  conciliar(mv: Movimiento): void {
    this.accion('conciliar', { movimiento_id: mv.id });
  }

  confirmar(modo: 'cancelar' | 'entregar'): void {
    this.accion(modo, modo === 'cancelar' ? { motivo: this.texto } : { entregado_a: this.texto });
  }

  accion(accion: 'cancelar' | 'entregar' | 'conciliar' | 'desconciliar', datos: object = {}): void {
    this.ocupado.set(true);
    this.servicio.accionPago(this.id, accion, datos).subscribe({
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
}
