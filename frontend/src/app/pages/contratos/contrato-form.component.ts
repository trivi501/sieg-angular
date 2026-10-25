import { Component, OnInit, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { money } from 'src/app/core/formato';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { ContratosService, DatosContrato, OpcionesContrato, opcionesPresupuesto } from './contratos.service';

/** Formulario del contrato (alta y edición): proveedor del padrón, vigencia, montos y partida de la secretaría. */
@Component({
  selector: 'app-contrato-form',
  imports: [FormsModule, SelectBusquedaDirective],
  template: `
    @if (opciones(); as o) {
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label" for="tipo">Tipo</label>
          <select id="tipo" class="form-select sin-busqueda" [(ngModel)]="datos.tipo">
            @for (t of tipos(o); track t[0]) {
              <option [value]="t[0]">{{ t[1] }}</option>
            }
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="numero">Número de contrato</label>
          <input id="numero" class="form-control" maxlength="100" [(ngModel)]="datos.numero_contrato" />
        </div>
        <div class="col-md-6">
          <label class="form-label" for="unidad">Secretaría</label>
          <select
            id="unidad"
            class="form-select"
            [ngModel]="datos.id_cat_egreso_unidad_administrativa"
            (ngModelChange)="cambiarUnidad($event)"
            [class.is-invalid]="e('id_cat_egreso_unidad_administrativa')"
          >
            <option [ngValue]="null">Seleccionar…</option>
            @for (u of o.unidades; track u.id) {
              <option [ngValue]="u.id">{{ u.clave }} — {{ u.nombre }}</option>
            }
          </select>
          <div class="invalid-feedback">{{ e('id_cat_egreso_unidad_administrativa') }}</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="proveedor">Proveedor (padrón)</label>
          <select id="proveedor" class="form-select" [(ngModel)]="datos.proveedor_id" [class.is-invalid]="e('proveedor_id')">
            <option [ngValue]="null">Seleccionar…</option>
            @for (p of o.proveedores; track p.id) {
              <option [ngValue]="p.id">{{ p.nombre }}{{ p.rfc ? ' (' + p.rfc + ')' : '' }}</option>
            }
          </select>
          <div class="invalid-feedback">{{ e('proveedor_id') }}</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="concepto">Concepto</label>
          <input
            id="concepto"
            class="form-control"
            maxlength="3000"
            placeholder="P. ej. Renta del edificio de la Secretaría de Gobierno"
            [(ngModel)]="datos.concepto"
            [class.is-invalid]="e('concepto')"
          />
          <div class="invalid-feedback">{{ e('concepto') }}</div>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="inicio">Inicio de vigencia</label>
          <input id="inicio" type="date" class="form-control" [(ngModel)]="datos.fecha_inicio" [class.is-invalid]="e('fecha_inicio')" />
          <div class="invalid-feedback">{{ e('fecha_inicio') }}</div>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="fin">Término</label>
          <input id="fin" type="date" class="form-control" [(ngModel)]="datos.fecha_fin" [class.is-invalid]="e('fecha_fin')" />
          <div class="invalid-feedback">{{ e('fecha_fin') }}</div>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="mensual">Monto mensual (con IVA)</label>
          <input
            id="mensual"
            type="number"
            step="0.01"
            min="0"
            class="form-control"
            [(ngModel)]="datos.monto_mensual"
            [class.is-invalid]="e('monto_mensual')"
          />
          <div class="invalid-feedback">{{ e('monto_mensual') }}</div>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="total">Monto total del contrato</label>
          <input
            id="total"
            type="number"
            step="0.01"
            min="0"
            class="form-control"
            [placeholder]="totalSugerido()"
            [(ngModel)]="datos.monto_total"
            [class.is-invalid]="e('monto_total')"
          />
          <div class="invalid-feedback">{{ e('monto_total') }}</div>
          <small class="text-muted">Vacío: {{ meses() }} meses × monto mensual.</small>
        </div>
        @let op = presupuesto();
        <div class="col-md-5">
          <label class="form-label" for="partida">Partida</label>
          <select
            id="partida"
            class="form-select"
            [ngModel]="datos.partida"
            (ngModelChange)="cambiarPartida($event)"
            [class.is-invalid]="e('partida')"
            [disabled]="!o.lineas.length"
          >
            <option value="">{{ o.lineas.length ? 'Partida…' : 'Elige la secretaría' }}</option>
            @for (p of op.partidas; track p.clave) {
              <option [value]="p.clave">{{ p.clave }} — {{ p.nombre }}</option>
            }
          </select>
          <div class="invalid-feedback">{{ e('partida') }}</div>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="proyecto">Proyecto</label>
          <select
            id="proyecto"
            class="form-select"
            [ngModel]="datos.proyecto"
            (ngModelChange)="cambiarProyecto($event)"
            [disabled]="!datos.partida"
          >
            <option value="">Proyecto…</option>
            @for (p of op.proyectos; track p.clave) {
              <option [value]="p.clave">{{ p.clave }} — {{ p.nombre }}</option>
            }
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="fuente">Fuente</label>
          <select id="fuente" class="form-select" [(ngModel)]="datos.fuente" [disabled]="!datos.proyecto">
            <option value="">Fuente…</option>
            @for (f of op.fuentes; track f.clave) {
              <option [value]="f.clave">{{ f.clave }} — {{ f.nombre }} ({{ money(f.disponible) }})</option>
            }
          </select>
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
export class ContratoFormComponent implements OnInit {
  private servicio = inject(ContratosService);

  /** Datos iniciales (edición); sin ellos, alta. */
  inicial = input<DatosContrato | null>(null);
  errores = input<Record<string, string>>({});
  guardando = input(false);
  textoGuardar = input('Guardar');
  guardar = output<DatosContrato>();
  cerrar = output<void>();

  readonly money = money;
  opciones = signal<OpcionesContrato | null>(null);
  datos: DatosContrato = {
    tipo: 'arrendamiento',
    numero_contrato: '',
    id_cat_egreso_unidad_administrativa: null,
    proveedor_id: null,
    concepto: '',
    fecha_inicio: '',
    fecha_fin: '',
    monto_mensual: null,
    monto_total: null,
    fuente: '',
    proyecto: '',
    partida: ''
  };

  ngOnInit(): void {
    const inicial = this.inicial();
    if (inicial) this.datos = { ...inicial };
    this.cargarOpciones(this.datos.id_cat_egreso_unidad_administrativa);
  }

  e(campo: string): string {
    return this.errores()[campo] ?? '';
  }

  tipos(o: OpcionesContrato): [string, string][] {
    return Object.entries(o.tipos);
  }

  presupuesto() {
    return opcionesPresupuesto(this.opciones()?.lineas ?? [], this.datos.partida, this.datos.proyecto);
  }

  meses(): number {
    if (!this.datos.fecha_inicio || !this.datos.fecha_fin || this.datos.fecha_fin < this.datos.fecha_inicio) return 0;
    const [a1, m1] = this.datos.fecha_inicio.split('-').map(Number);
    const [a2, m2] = this.datos.fecha_fin.split('-').map(Number);
    return (a2 - a1) * 12 + (m2 - m1) + 1;
  }

  totalSugerido(): string {
    return this.datos.monto_mensual ? String(Math.round(this.meses() * this.datos.monto_mensual * 100) / 100) : '';
  }

  cambiarUnidad(id: number | null): void {
    this.datos.id_cat_egreso_unidad_administrativa = id;
    this.datos = { ...this.datos, partida: '', proyecto: '', fuente: '' };
    this.cargarOpciones(id);
  }

  cambiarPartida(valor: string): void {
    this.datos = { ...this.datos, partida: valor, proyecto: '', fuente: '' };
    const ps = this.presupuesto().proyectos;
    if (ps.length === 1) this.cambiarProyecto(ps[0].clave);
  }

  cambiarProyecto(valor: string): void {
    this.datos = { ...this.datos, proyecto: valor, fuente: '' };
    const fs = this.presupuesto().fuentes;
    if (fs.length === 1) this.datos.fuente = fs[0].clave;
  }

  private cargarOpciones(unidad: number | null): void {
    this.servicio.opciones(unidad).subscribe((o) => {
      this.opciones.set(o);
      if (!this.datos.id_cat_egreso_unidad_administrativa && o.unidad) this.datos.id_cat_egreso_unidad_administrativa = o.unidad;
    });
  }
}
