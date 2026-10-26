import { Component, OnInit, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { DatosNomina, NominaService, OpcionTipo, TipoNomina } from './nomina.service';

/**
 * Encabezado de la nómina (alta y edición): tipo, periodo, fecha de pago, concepto y a favor de quién va la CLC.
 * Finiquito y laudo: la persona, su número de empleado y el oficio o expediente que respalda el pago.
 */
@Component({
  selector: 'app-nomina-form',
  imports: [FormsModule],
  template: `
    @if (tipos().length) {
      @let individual = tipoActual()?.individual ?? false;
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label" for="tipo">Tipo</label>
          <select
            id="tipo"
            class="form-select sin-busqueda"
            [ngModel]="datos.tipo"
            (ngModelChange)="cambiarTipo($event)"
            [disabled]="!!inicial()"
          >
            @for (t of tipos(); track t.clave) {
              <option [value]="t.clave">{{ t.nombre }}</option>
            }
          </select>
        </div>
        @if (!individual) {
          <div class="col-md-4">
            <label class="form-label" for="periodo">Periodo</label>
            <input
              id="periodo"
              class="form-control"
              maxlength="150"
              placeholder="P. ej. 1a quincena de octubre 2026"
              [(ngModel)]="datos.periodo"
              [class.is-invalid]="e('periodo')"
            />
            <div class="invalid-feedback">{{ e('periodo') }}</div>
          </div>
        }
        <div class="col-md-4">
          <label class="form-label" for="fecha">Fecha de pago</label>
          <input id="fecha" type="date" class="form-control" [(ngModel)]="datos.fecha_pago" [class.is-invalid]="e('fecha_pago')" />
          <div class="invalid-feedback">{{ e('fecha_pago') }}</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="concepto">Concepto</label>
          <input
            id="concepto"
            class="form-control"
            maxlength="1000"
            [placeholder]="individual ? 'P. ej. Finiquito por renuncia voluntaria' : 'P. ej. Nómina ordinaria'"
            [(ngModel)]="datos.concepto"
            [class.is-invalid]="e('concepto')"
          />
          <div class="invalid-feedback">{{ e('concepto') }}</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="beneficiario">{{ individual ? 'Nombre del trabajador o actor' : 'A favor de (beneficiario de la CLC)' }}</label>
          <input
            id="beneficiario"
            class="form-control"
            maxlength="300"
            [(ngModel)]="datos.beneficiario"
            [class.is-invalid]="e('beneficiario')"
          />
          <div class="invalid-feedback">{{ e('beneficiario') }}</div>
          @if (datos.tipo === 'seguridad_social') {
            <small class="text-muted">IMSS o ISSSTEZAC, según el archivo.</small>
          }
        </div>
        @if (individual) {
          <div class="col-md-3">
            <label class="form-label" for="numero">Número de empleado</label>
            <input id="numero" class="form-control" maxlength="50" [(ngModel)]="datos.beneficiario_numero" />
          </div>
        }
        <div [class]="individual ? 'col-md-5' : 'col-md-6'">
          <label class="form-label" for="referencia">{{ individual ? 'Oficio o expediente (obligatorio)' : 'Referencia (número de nómina en Nomipac)' }}</label>
          <input
            id="referencia"
            class="form-control"
            maxlength="200"
            [(ngModel)]="datos.referencia"
            [class.is-invalid]="e('referencia')"
          />
          <div class="invalid-feedback">{{ e('referencia') }}</div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="button" class="btn btn-primary" [disabled]="guardando()" (click)="guardar.emit(datos)">
          {{ guardando() ? 'Guardando…' : textoGuardar() }}
        </button>
        <button type="button" class="btn btn-outline-secondary" (click)="cerrar.emit()">Cancelar</button>
      </div>
    } @else {
      <p class="text-muted">Cargando…</p>
    }
  `
})
export class NominaFormComponent implements OnInit {
  private servicio = inject(NominaService);

  /** Datos iniciales (edición); sin ellos, alta. */
  inicial = input<DatosNomina | null>(null);
  errores = input<Record<string, string>>({});
  guardando = input(false);
  textoGuardar = input('Guardar');
  guardar = output<DatosNomina>();
  cerrar = output<void>();

  tipos = signal<OpcionTipo[]>([]);
  datos: DatosNomina = { tipo: 'nomina', periodo: '', fecha_pago: '', concepto: '', beneficiario: '', beneficiario_numero: '', referencia: '' };

  ngOnInit(): void {
    const inicial = this.inicial();
    if (inicial) this.datos = { ...inicial };
    this.servicio.opciones().subscribe((o) => {
      this.tipos.set(o.tipos);
      if (!inicial) this.cambiarTipo(this.datos.tipo);
    });
  }

  e(campo: string): string {
    return this.errores()[campo] ?? '';
  }

  tipoActual(): OpcionTipo | undefined {
    return this.tipos().find((t) => t.clave === this.datos.tipo);
  }

  /** Al cambiar el tipo se propone su beneficiario (cuenta de nómina, IMSS) si no se ha escrito otro. */
  cambiarTipo(tipo: TipoNomina): void {
    const sugeridos = this.tipos().map((t) => t.beneficiario ?? '');
    const beneficiario = !this.datos.beneficiario || sugeridos.includes(this.datos.beneficiario) ? (this.tipos().find((t) => t.clave === tipo)?.beneficiario ?? '') : this.datos.beneficiario;
    this.datos = { ...this.datos, tipo, beneficiario };
  }
}
