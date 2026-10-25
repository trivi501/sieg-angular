import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { from, of } from 'rxjs';
import { catchError, concatMap, map, toArray } from 'rxjs/operators';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { AccionComponent } from 'src/app/shared/accion.component';
import { claseEstadoTramite } from '../servicios/servicios.service';
import { CombustibleService, DetalleLote, litros } from './combustible.service';

type Resultado = { archivo: string; ok: boolean; texto: string; aviso?: string | null };

/**
 * Una carga del Excel del proveedor: sus bitácoras (una por secretaría) y la subida de los XML. Cada XML va solo a
 * la bitácora que tiene ese folio de factura en el Excel.
 */
@Component({
  selector: 'app-combustible-lote',
  imports: [RouterLink, DatePipe, AccionComponent],
  template: `
    @if (error()) {
      <div class="alert alert-danger">{{ error() }}</div>
    } @else if (d(); as d) {
      @let l = d.lote;
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <a routerLink="/combustible" class="small text-muted">← Bitácora de combustible</a>
          <h4 class="mb-1">Combustible {{ l.periodo }}</h4>
          <p class="text-muted small mb-0">
            {{ l.archivo }} · hoja {{ l.hoja }} · cargó {{ l.capturo ?? '—' }} el {{ l.fecha | date: 'dd/MM/yyyy HH:mm' }}
          </p>
        </div>
        <div class="d-flex gap-2">
          @if (d.puede.subir) {
            <label class="btn btn-primary mb-0" [class.disabled]="ocupado()">
              <input
                type="file"
                class="d-none"
                accept=".xml,text/xml,application/xml"
                multiple
                (change)="subir($event)"
                [disabled]="ocupado()"
              />
              {{ ocupado() ? 'Verificando en el SAT… ' + avance() : 'Subir XML de las facturas' }}
            </label>
          }
          @if (d.puede.eliminar) {
            <button
              type="button"
              class="btn btn-outline-danger"
              (click)="eliminar()"
              [disabled]="ocupado()"
              appAccion="Eliminar esta carga"
              icono="delete"
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

      <div class="card">
        <div class="card-body">
          <div class="row g-3 small mb-3">
            <div class="col-6 col-md-2">
              <span class="text-muted d-block">Bitácoras</span>
              <b>{{ d.bitacoras.length }}</b>
            </div>
            <div class="col-6 col-md-2">
              <span class="text-muted d-block">Tickets</span>
              <b>{{ l.tickets }}</b>
            </div>
            <div class="col-6 col-md-2">
              <span class="text-muted d-block">XML</span>
              <b>{{ cargadas() }} / {{ esperadas() }}</b>
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Litros</span>
              <b class="font-monospace">{{ litros(l.litros) }}</b>
            </div>
            <div class="col-6 col-md-3">
              <span class="text-muted d-block">Importe</span>
              <b class="font-monospace">{{ money(l.importe) }}</b>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Folio</th>
                  <th>Secretaría</th>
                  <th class="text-center">Tickets</th>
                  <th class="text-center">XML</th>
                  <th class="text-end">Litros</th>
                  <th class="text-end">Importe</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                @for (b of d.bitacoras; track b.id) {
                  <tr role="button" (click)="abrir(b.id)">
                    <td class="fw-semibold text-nowrap">
                      <a [routerLink]="['/combustible', b.id]">{{ b.folio }}</a>
                    </td>
                    <td class="small">{{ b.unidad }}</td>
                    <td class="text-center">{{ b.tickets }}</td>
                    <td class="text-center">
                      <span
                        [class.text-success]="b.facturas_cargadas === b.facturas_esperadas"
                        [class.text-warning]="b.facturas_cargadas < b.facturas_esperadas"
                      >
                        {{ b.facturas_cargadas }} / {{ b.facturas_esperadas }}
                      </span>
                    </td>
                    <td class="text-end font-monospace">{{ litros(b.litros) }}</td>
                    <td class="text-end font-monospace">{{ money(b.importe) }}</td>
                    <td>
                      <span class="badge {{ clase(b.estado) }}">{{ b.estado_texto }}</span>
                      @if (b.requisicion) {
                        <small class="d-block text-muted">{{ b.requisicion }}</small>
                      }
                    </td>
                  </tr>
                } @empty {
                  <tr>
                    <td colspan="7" class="text-center text-muted py-4">No tienes bitácoras de esta carga.</td>
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
export class CombustibleLoteComponent {
  private servicio = inject(CombustibleService);
  private router = inject(Router);

  readonly id = Number(inject(ActivatedRoute).snapshot.paramMap.get('id'));
  readonly money = money;
  readonly litros = litros;
  readonly clase = claseEstadoTramite;

  d = signal<DetalleLote | null>(null);
  error = signal('');
  aviso = signal<{ tipo: 'success' | 'danger'; texto: string } | null>(
    history.state?.mensaje ? { tipo: 'success', texto: history.state.mensaje } : null
  );
  ocupado = signal(false);
  avance = signal('');
  resultados = signal<Resultado[]>([]);

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.lote(this.id).subscribe({
      next: (d) => this.d.set(d),
      error: (e: HttpErrorResponse) => this.error.set(e.status === 404 ? 'No existe.' : erroresDe(e).mensaje)
    });
  }

  esperadas(): number {
    return (this.d()?.bitacoras ?? []).reduce((s, b) => s + b.facturas_esperadas, 0);
  }
  cargadas(): number {
    return (this.d()?.bitacoras ?? []).reduce((s, b) => s + b.facturas_cargadas, 0);
  }
  correctos(): number {
    return this.resultados().filter((r) => r.ok).length;
  }

  abrir(id: number): void {
    this.router.navigate(['/combustible', id]);
  }

  /** Uno por uno (cada uno se verifica en el SAT y bloquea su bitácora); al final, el resultado de todos. */
  subir(evento: Event): void {
    const input = evento.target as HTMLInputElement;
    const archivos = Array.from(input.files ?? []);
    if (!archivos.length) return;
    this.ocupado.set(true);
    this.aviso.set(null);
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
            map((r) => {
              this.avance.set(`${++hechos}/${archivos.length}`);
              return r;
            })
          )
        ),
        toArray()
      )
      .subscribe((rs) => {
        this.ocupado.set(false);
        input.value = '';
        this.resultados.set(rs);
        this.cargar();
      });
  }

  eliminar(): void {
    const l = this.d()!.lote;
    if (
      !confirm(
        `¿Eliminar la carga de ${l.periodo} con sus ${this.d()!.bitacoras.length} bitácoras, tickets y XML? Después se puede volver a cargar el Excel.`
      )
    )
      return;
    this.servicio.eliminarLote(this.id).subscribe({
      next: () => this.router.navigate(['/combustible']),
      error: (e: HttpErrorResponse) => this.aviso.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }
}
