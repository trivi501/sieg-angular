import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { from, of } from 'rxjs';
import { catchError, concatMap, map, tap, toArray } from 'rxjs/operators';

import { Paginado, erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AuthService } from 'src/app/core/auth/auth.service';
import { PaginacionComponent } from '../admin/paginacion.component';
import { AccionComponent } from 'src/app/shared/accion.component';
import { CombustibleService, FacturaPorAsignar, LoteCombustible, PreviaCombustible, litros } from './combustible.service';

type Resultado = { archivo: string; ok: boolean; texto: string; aviso?: string | null };

/**
 * Cargas del proveedor de combustible (unidad de combustibles): primero se suben los XML, que quedan por asignar;
 * luego el Excel (una hoja por mes). Cada factura del Excel es de una secretaría, así que se crea una bitácora por
 * secretaría con sus tickets y sus XML. El orden contrario también funciona.
 */
@Component({
  selector: 'app-combustible',
  imports: [FormsModule, RouterLink, DatePipe, PaginacionComponent, AccionComponent],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div>
        <h4 class="mb-1">Cargas del proveedor de combustible</h4>
        <p class="text-muted small mb-0">
          <b>1.</b>
          Sube las facturas (XML) del proveedor.
          <b>2.</b>
          Carga su Excel: cada factura se va a la bitácora de su secretaría con sus tickets.
          <b>3.</b>
          Cada secretaría llena su bitácora (presupuesto y concepto) y solicita la validación; al validarse, si hay suficiencia, se generan
          la requisición, la orden de compra y la CLC.
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a routerLink="/combustible/bitacoras" class="btn btn-outline-secondary">Bitácoras</a>
        <a routerLink="/combustible/reporte" class="btn btn-outline-secondary">Reporte de consumo</a>
        @if (puedeCargar) {
          <label class="btn btn-outline-primary mb-0" [class.disabled]="subiendo()">
            <input
              type="file"
              class="d-none"
              accept=".xml,text/xml,application/xml"
              multiple
              (change)="subir($event)"
              [disabled]="subiendo()"
            />
            {{ subiendo() ? 'Verificando en el SAT… ' + avance() : '1. Subir facturas (XML)' }}
          </label>
          <button type="button" class="btn btn-primary" (click)="abrirCarga()">2. Cargar Excel</button>
        }
      </div>
    </div>

    @if (aviso(); as a) {
      <div class="alert alert-{{ a.tipo }} alert-dismissible py-2">
        {{ a.texto }}
        <button type="button" class="btn-close" (click)="aviso.set(null)"></button>
      </div>
    }

    @if (resultados().length) {
      <div class="card mb-3">
        <div class="card-body py-2 small">
          <div class="d-flex justify-content-between">
            <b>{{ correctos() }} de {{ resultados().length }} XML agregados</b>
            <button type="button" class="btn-close" (click)="resultados.set([])"></button>
          </div>
          <ul class="mb-0 mt-1">
            @for (r of resultados(); track $index) {
              <li [class.text-danger]="!r.ok">
                {{ r.archivo }}: {{ r.texto }}
                @if (r.aviso) {
                  <span class="text-warning d-block">{{ r.aviso }}</span>
                }
              </li>
            }
          </ul>
        </div>
      </div>
    }

    <!-- XML subidos que todavía no están en ningún Excel cargado -->
    @if (porAsignar().length) {
      <div class="card mb-3 border-warning">
        <div class="card-body">
          <h6 class="mb-1">Facturas por asignar ({{ porAsignar().length }} · {{ money(totalPorAsignar()) }})</h6>
          <p class="small text-muted mb-2">
            Ya verificadas en el SAT. Al cargar el Excel que las incluya, cada una pasa sola a la bitácora de su secretaría.
          </p>
          <div class="table-responsive" style="max-height: 260px">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Folio</th>
                  <th>Emisor</th>
                  <th>Fecha</th>
                  <th class="text-end">Total</th>
                  <th>SAT</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                @for (f of porAsignar(); track f.id) {
                  <tr>
                    <td class="fw-semibold">{{ f.folio }}</td>
                    <td class="small">
                      {{ f.emisor }}
                      <span class="text-muted">({{ f.rfc_emisor }})</span>
                    </td>
                    <td>{{ f.fecha | date: 'dd/MM/yyyy' }}</td>
                    <td class="text-end font-monospace">{{ money(f.total) }}</td>
                    <td>{{ f.sat_estado }}</td>
                    <td class="text-end">
                      @if (puedeCargar) {
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-danger"
                          (click)="quitar(f)"
                          appAccion="Quitar factura"
                          icono="delete"
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

    <!-- Carga del Excel: primero se lee (vista previa) y luego se confirma -->
    @if (cargando()) {
      <div class="card mb-3">
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-md-5">
              <label class="form-label" for="archivo">Excel del proveedor (.xlsx)</label>
              <input id="archivo" type="file" class="form-control" accept=".xlsx,.xls" (change)="elegir($event)" [disabled]="ocupado()" />
            </div>
            @if (previa(); as p) {
              <div class="col-6 col-md-2">
                <label class="form-label" for="hoja">Hoja</label>
                <select id="hoja" class="form-select" [ngModel]="hoja" (ngModelChange)="cambiarHoja($event)" [disabled]="ocupado()">
                  @for (h of p.hojas; track h) {
                    <option [value]="h">{{ h }}</option>
                  }
                </select>
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label" for="periodo">Periodo</label>
                <input id="periodo" class="form-control" maxlength="100" [(ngModel)]="periodo" [class.is-invalid]="errores()['periodo']" />
                <div class="invalid-feedback">{{ errores()['periodo'] }}</div>
              </div>
            }
          </div>
          @if (ocupado() && !previa()) {
            <p class="text-muted small mt-3 mb-0">Leyendo el Excel…</p>
          }
          @if (errores()['archivo']; as e) {
            <div class="alert alert-danger py-2 mt-3 mb-0">{{ e }}</div>
          }

          @if (previa(); as p) {
            <div class="row g-3 small mt-1">
              <div class="col-6 col-md-2">
                <span class="text-muted d-block">Tickets</span>
                <b>{{ p.tickets }}</b>
              </div>
              <div class="col-6 col-md-2">
                <span class="text-muted d-block">Facturas</span>
                <b>{{ p.facturas }}</b>
              </div>
              <div class="col-6 col-md-2">
                <span class="text-muted d-block">Litros</span>
                <b class="font-monospace">{{ litros(p.litros) }}</b>
              </div>
              <div class="col-6 col-md-2">
                <span class="text-muted d-block">Importe</span>
                <b class="font-monospace">{{ money(p.importe) }}</b>
              </div>
              <div class="col-12 col-md-4">
                <span class="text-muted d-block">Proveedor (según el Excel)</span>
                {{ p.proveedores.join(' · ') || '—' }}
              </div>
              <div class="col-12">
                <b [class.text-success]="p.facturas_con_xml === p.facturas" [class.text-warning]="p.facturas_con_xml < p.facturas">
                  {{ p.facturas_con_xml }} de {{ p.facturas }} facturas ya tienen su XML subido
                </b>
                (pasan solas a su bitácora{{ p.facturas_con_xml < p.facturas ? '; las demás se pueden subir después' : '' }}).
                @if (p.por_asignar_fuera) {
                  <span class="text-muted">{{ p.por_asignar_fuera }} XML por asignar no vienen en este Excel y siguen esperando.</span>
                }
              </div>
              <div class="col-12 text-muted">
                {{ p.vehiculos_padron }} de {{ p.tickets }} tickets coinciden con un vehículo del padrón por número económico. Las facturas
                se ligan por su folio (serie y folio del XML).
              </div>
            </div>

            @if (p.total_errores) {
              <div class="alert alert-danger py-2 mt-3 small">
                <b>No se puede cargar: {{ p.total_errores }} error(es).</b>
                Corrígelos en el Excel y vuelve a elegirlo.
                <ul class="mb-0 mt-1">
                  @for (e of p.errores; track $index) {
                    <li>{{ e }}</li>
                  }
                </ul>
              </div>
            }
            @if (p.total_avisos) {
              <div class="alert alert-warning py-2 mt-3 small">
                <b>{{ p.total_avisos }} aviso(s)</b>
                (no impiden cargarlo):
                <ul class="mb-0 mt-1">
                  @for (a of p.avisos; track $index) {
                    <li>{{ a }}</li>
                  }
                </ul>
              </div>
            }

            <div class="table-responsive mt-3">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Clave</th>
                    <th>Secretaría (catálogo)</th>
                    <th>En el Excel</th>
                    <th class="text-center">Tickets</th>
                    <th class="text-center">Facturas</th>
                    <th class="text-end">Litros</th>
                    <th class="text-end">Importe</th>
                  </tr>
                </thead>
                <tbody>
                  @for (s of p.secretarias; track s.clave) {
                    <tr [class.table-danger]="!s.unidad_id">
                      <td class="fw-semibold">{{ s.clave }}</td>
                      <td>{{ s.nombre ?? 'No existe en el catálogo' }}</td>
                      <td class="text-muted">{{ s.nombre_excel }}</td>
                      <td class="text-center">{{ s.tickets }}</td>
                      <td class="text-center">{{ s.facturas }}</td>
                      <td class="text-end font-monospace">{{ litros(s.litros) }}</td>
                      <td class="text-end font-monospace">{{ money(s.importe) }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }
        </div>
        <div class="card-footer d-flex gap-2">
          <button
            type="button"
            class="btn btn-primary"
            [disabled]="ocupado() || !previa() || !!previa()?.total_errores || !periodo.trim()"
            (click)="importar()"
          >
            {{ ocupado() && previa() ? 'Cargando…' : 'Crear ' + (previa()?.secretarias?.length ?? 0) + ' bitácora(s)' }}
          </button>
          <button type="button" class="btn btn-outline-secondary" (click)="cerrarCarga()" [disabled]="ocupado()">Cancelar</button>
        </div>
      </div>
    }

    <!-- Cargas anteriores -->
    <div class="card">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Periodo</th>
                <th>Archivo</th>
                <th>Cargado</th>
                <th class="text-center">Bitácoras</th>
                <th class="text-center">XML</th>
                <th class="text-end">Litros</th>
                <th class="text-end">Importe</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              @for (l of datos()?.data ?? []; track l.id) {
                <tr role="button" (click)="abrir(l.id)">
                  <td class="fw-semibold">
                    <a [routerLink]="['/combustible/lotes', l.id]">{{ l.periodo }}</a>
                  </td>
                  <td class="small">
                    {{ l.archivo }}
                    <span class="d-block text-muted">Hoja {{ l.hoja }} · {{ l.tickets }} tickets</span>
                  </td>
                  <td class="small text-nowrap">
                    {{ l.fecha | date: 'dd/MM/yyyy HH:mm' }}
                    <span class="d-block text-muted">{{ l.capturo }}</span>
                  </td>
                  <td class="text-center">{{ l.bitacoras }}</td>
                  <td class="text-center">
                    <span
                      [class.text-success]="l.facturas_cargadas === l.facturas_esperadas"
                      [class.text-warning]="l.facturas_cargadas < l.facturas_esperadas"
                    >
                      {{ l.facturas_cargadas }} / {{ l.facturas_esperadas }}
                    </span>
                  </td>
                  <td class="text-end font-monospace">{{ litros(l.litros) }}</td>
                  <td class="text-end font-monospace">{{ money(l.importe) }}</td>
                  <td class="small">
                    @for (e of estadosDe(l); track e[0]) {
                      <span class="d-block">{{ e[1] }} {{ nombresEstado[e[0]] ?? e[0] }}</span>
                    }
                  </td>
                </tr>
              } @empty {
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">
                    {{ datos() ? 'Aún no se ha cargado ningún Excel del proveedor.' : 'Cargando…' }}
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
        @if (datos(); as d) {
          <app-paginacion [actual]="d.current_page" [ultima]="d.last_page" [total]="d.total" (cambiar)="cargar($event)" />
        }
      </div>
    </div>
  `,
  styles: `
    td,
    th {
      font-size: 13px;
    }
  `
})
export class CombustibleComponent {
  private servicio = inject(CombustibleService);
  private router = inject(Router);

  readonly money = money;
  readonly litros = litros;
  readonly nombresEstado: Record<string, string> = {
    captura: 'en captura',
    por_validar: 'por validar',
    rechazado: 'no validadas',
    validado: 'validadas',
    cancelado: 'canceladas'
  };

  datos = signal<Paginado<LoteCombustible> | null>(null);
  aviso = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(null);

  // Carga del Excel
  cargando = signal(false);
  previa = signal<PreviaCombustible | null>(null);
  ocupado = signal(false);
  errores = signal<Record<string, string>>({});
  archivo: File | null = null;
  hoja: string | null = null;
  periodo = '';

  // XML del proveedor
  readonly puedeCargar = inject(AuthService).can('combustible-cargar');
  porAsignar = signal<FacturaPorAsignar[]>([]);
  subiendo = signal(false);
  avance = signal('');
  resultados = signal<Resultado[]>([]);

  constructor() {
    this.cargar(1);
    this.cargarPorAsignar();
  }

  cargar(page: number): void {
    this.servicio.lotes(page).subscribe((d) => this.datos.set(d));
  }

  cargarPorAsignar(): void {
    if (this.puedeCargar) this.servicio.porAsignar().subscribe((f) => this.porAsignar.set(f));
  }

  totalPorAsignar(): number {
    return this.porAsignar().reduce((s, f) => s + f.total, 0);
  }
  correctos(): number {
    return this.resultados().filter((r) => r.ok).length;
  }

  /** Uno por uno (cada uno se verifica en el SAT): va a su bitácora si ya hay Excel, si no queda por asignar. */
  subir(evento: Event): void {
    const input = evento.target as HTMLInputElement;
    const archivos = Array.from(input.files ?? []);
    if (!archivos.length) return;
    this.subiendo.set(true);
    this.resultados.set([]);
    let hechos = 0;
    this.avance.set(`0/${archivos.length}`);
    from(archivos)
      .pipe(
        concatMap((a) =>
          this.servicio.subirFactura(a).pipe(
            map((r): Resultado => ({ archivo: a.name, ok: true, texto: r.message, aviso: r.aviso })),
            catchError((e: HttpErrorResponse) => {
              const { campos, mensaje } = erroresDe(e);
              return of<Resultado>({ archivo: a.name, ok: false, texto: campos['xml'] ?? mensaje });
            }),
            tap(() => this.avance.set(`${++hechos}/${archivos.length}`))
          )
        ),
        toArray()
      )
      .subscribe((rs) => {
        this.subiendo.set(false);
        input.value = '';
        this.resultados.set(rs);
        this.cargar(this.datos()?.current_page ?? 1);
        this.cargarPorAsignar();
      });
  }

  quitar(f: FacturaPorAsignar): void {
    if (!confirm(`¿Quitar la factura ${f.folio}? Se borra su XML.`)) return;
    this.servicio.quitarPorAsignar(f.id).subscribe({
      next: (r) => {
        this.aviso.set({ tipo: 'success', texto: r.message });
        this.cargarPorAsignar();
      },
      error: (e: HttpErrorResponse) => this.aviso.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }

  abrir(id: number): void {
    this.router.navigate(['/combustible/lotes', id]);
  }

  estadosDe(l: LoteCombustible): [string, number][] {
    return Object.entries(l.estados) as [string, number][];
  }

  abrirCarga(): void {
    this.cargando.set(true);
    this.previa.set(null);
    this.errores.set({});
    this.archivo = null;
  }

  cerrarCarga(): void {
    this.cargando.set(false);
    this.previa.set(null);
  }

  elegir(evento: Event): void {
    this.archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    this.hoja = null;
    this.leer();
  }

  cambiarHoja(hoja: string): void {
    this.hoja = hoja;
    this.leer();
  }

  private leer(): void {
    if (!this.archivo) return;
    this.ocupado.set(true);
    this.errores.set({});
    this.servicio.previa(this.archivo, this.hoja).subscribe({
      next: (p) => {
        this.ocupado.set(false);
        this.previa.set(p);
        this.hoja = p.hoja;
        this.periodo = p.periodo;
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        this.previa.set(null);
        const { campos, mensaje } = erroresDe(e);
        this.errores.set({ archivo: campos['archivo'] ?? mensaje });
      }
    });
  }

  importar(): void {
    if (!this.archivo) return;
    this.ocupado.set(true);
    this.errores.set({});
    this.servicio.importar(this.archivo, this.hoja, this.periodo).subscribe({
      next: (r) => this.router.navigate(['/combustible/lotes', r.id], { state: { mensaje: r.message } }),
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(Object.keys(campos).length ? campos : { archivo: mensaje });
      }
    });
  }
}
