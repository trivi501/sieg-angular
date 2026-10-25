import { Component, OnInit, input, output } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { DatosPadron, datosVacios } from './padron.service';

/** Datos del proveedor en el padrón (alta y edición). */
@Component({
  selector: 'app-padron-datos',
  imports: [FormsModule],
  template: `
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="nombre">Nombre o razón social</label>
        <input id="nombre" class="form-control" maxlength="300" [(ngModel)]="datos.nombre" [class.is-invalid]="e('nombre')" />
        <div class="invalid-feedback">{{ e('nombre') }}</div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="rfc">RFC</label>
        <input id="rfc" class="form-control text-uppercase" maxlength="13" [(ngModel)]="datos.rfc" [class.is-invalid]="e('rfc')" />
        <div class="invalid-feedback">{{ e('rfc') }}</div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="persona">Tipo de persona</label>
        <select id="persona" class="form-select sin-busqueda" [(ngModel)]="datos.tipo_persona">
          <option [ngValue]="null">Según el RFC</option>
          <option value="fisica">Persona física</option>
          <option value="moral">Persona moral</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="giro">Giro o actividad</label>
        <input id="giro" class="form-control" maxlength="500" [(ngModel)]="datos.giro" />
      </div>
      <div class="col-md-6">
        <label class="form-label" for="representante">Representante legal</label>
        <input id="representante" class="form-control" maxlength="300" [(ngModel)]="datos.representante_legal" />
      </div>
      <div class="col-md-4">
        <label class="form-label" for="regimen">Régimen fiscal</label>
        <input
          id="regimen"
          class="form-control"
          maxlength="200"
          placeholder="P. ej. 601 General de Ley Personas Morales"
          [(ngModel)]="datos.regimen_fiscal"
        />
      </div>
      <div class="col-md-4">
        <label class="form-label" for="correo">Correo</label>
        <input id="correo" type="email" class="form-control" maxlength="150" [(ngModel)]="datos.correo" [class.is-invalid]="e('correo')" />
        <div class="invalid-feedback">{{ e('correo') }}</div>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="telefono">Teléfono</label>
        <input id="telefono" class="form-control" maxlength="30" [(ngModel)]="datos.telefono" />
      </div>
      <div class="col-md-8">
        <label class="form-label" for="domicilio">Domicilio fiscal</label>
        <input id="domicilio" class="form-control" maxlength="500" [(ngModel)]="datos.domicilio" />
      </div>
      <div class="col-md-4">
        <label class="form-label" for="clabe">CLABE</label>
        <input id="clabe" class="form-control" maxlength="18" [(ngModel)]="datos.clabe" [class.is-invalid]="e('clabe')" />
        <div class="invalid-feedback">{{ e('clabe') }}</div>
      </div>
      <div class="col-12">
        <div class="form-check">
          <input id="eventual" type="checkbox" class="form-check-input" [(ngModel)]="datos.compra_unica" />
          <label class="form-check-label" for="eventual">
            Proveedor de compra única (eventual): se identifica aparte y no lleva cédula
          </label>
        </div>
      </div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button type="button" class="btn btn-primary" [disabled]="guardando()" (click)="guardar.emit(datos)">
        {{ guardando() ? 'Guardando…' : textoGuardar() }}
      </button>
      <button type="button" class="btn btn-outline-secondary" (click)="cerrar.emit()">Cancelar</button>
    </div>
  `
})
export class PadronDatosComponent implements OnInit {
  inicial = input<DatosPadron | null>(null);
  errores = input<Record<string, string>>({});
  guardando = input(false);
  textoGuardar = input('Guardar');
  guardar = output<DatosPadron>();
  cerrar = output<void>();

  datos: DatosPadron = datosVacios();

  ngOnInit(): void {
    const inicial = this.inicial();
    if (inicial) this.datos = { ...inicial };
  }

  e(campo: string): string {
    return this.errores()[campo] ?? '';
  }
}
