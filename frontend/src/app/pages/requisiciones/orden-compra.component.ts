import { Component, TemplateRef, computed, inject, input, output, signal, viewChild } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { NgbModal, NgbModalRef } from '@ng-bootstrap/ng-bootstrap';
import { Observable } from 'rxjs';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { DetalleRequisicion, ProveedorOrden, RequisicionesService, abrirBlob } from './requisiciones.service';
import { AlmacenService } from '../almacen/almacen.service';
import { AccionComponent } from 'src/app/shared/accion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

export interface AvisoOrden {
  tipo: 'success' | 'danger' | 'warning';
  texto: string;
  /** Aviso adicional (p. ej. emisor en la lista de EFOS). */
  extra?: string | null;
}

type Dialogo = 'generar' | 'proveedor' | 'fecha' | 'refacturar' | 'cancelar' | 'generarClc' | 'cancelarClc';

/**
 * Orden de compra de una requisición comprometida (SIEG: sección de la orden en Show.tsx): generarla con
 * su proveedor, cambiar proveedor o fecha, PDF, factura XML con su estado en el SAT, refactura, cancelación
 * y su CLC (generar, imprimir, cancelar y el recorrido del trámite).
 */
@Component({
  selector: 'app-orden-compra',
  imports: [SelectBusquedaDirective, AccionComponent, FormsModule, DatePipe, RouterLink],
  templateUrl: './orden-compra.component.html'
})
export class OrdenCompraComponent {
  private servicio = inject(RequisicionesService);
  private almacen = inject(AlmacenService);
  private modal = inject(NgbModal);

  readonly d = input.required<DetalleRequisicion>();
  readonly actualizado = output<AvisoOrden>();

  private tplDialogo = viewChild.required<TemplateRef<unknown>>('dialogoTpl');

  readonly money = money;
  readonly hoy = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Mexico_City' });

  orden = computed(() => this.d().orden);
  puede = computed(() => this.d().puede);

  // Diálogos
  dialogo = signal<Dialogo | null>(null);
  proveedores = signal<ProveedorOrden[]>([]);
  proveedorId: number | null = null;
  fecha = '';
  observaciones = '';
  motivo = '';
  errores = signal<Record<string, string>>({});
  ocupado = signal(false);

  // Alta rápida de proveedor
  altaAbierta = signal(false);
  nuevoNombre = '';
  nuevoRfc = '';
  errorAlta = signal('');

  // Factura
  archivo: File | null = null;
  errorXml = signal('');

  proveedorElegido = computed(() => this.proveedores().find((p) => p.id === this.proveedorId));

  readonly titulos: Record<Dialogo, { titulo: string; boton: string; clase: string; texto: string }> = {
    generar: {
      titulo: 'Generar orden de compra',
      boton: 'Generar orden',
      clase: 'btn-primary',
      texto: 'El importe es el total cotizado de la requisición. La requisición pasa a Orden de Compra.'
    },
    proveedor: { titulo: 'Cambiar proveedor', boton: 'Cambiar proveedor', clase: 'btn-primary', texto: 'Solo mientras la orden no tenga factura. Queda en la bitácora.' },
    fecha: { titulo: 'Cambiar fecha de la orden', boton: 'Guardar fecha', clase: 'btn-primary', texto: 'Del mismo año del folio, no futura y no posterior al pago.' },
    refacturar: {
      titulo: 'Refacturar',
      boton: 'Refacturar',
      clase: 'btn-warning',
      texto:
        'El proveedor volvió a facturar: se quita la factura vigente (queda en el historial) y se cancela su CLC si la tenía. Al cargar el nuevo XML se genera una CLC nueva.'
    },
    generarClc: {
      titulo: 'Generar CLC',
      boton: 'Generar CLC',
      clase: 'btn-primary',
      texto:
        'La cuenta por liquidar certificada es la solicitud de pago al proveedor. Queda en el área para que la envíe a Recursos Materiales (Trámite de CLC).'
    },
    cancelarClc: {
      titulo: 'Cancelar CLC',
      boton: 'Cancelar CLC',
      clase: 'btn-danger',
      texto:
        'Sale del trámite; si ya estaba pagada, el pago queda anulado. No se genera otra CLC: después sigue cancelar la orden de compra. No se puede deshacer.'
    },
    cancelar: {
      titulo: 'Cancelar orden de compra',
      boton: 'Cancelar orden',
      clase: 'btn-danger',
      texto:
        'Se cancela con su factura (deja de contar como devengado; la factura se puede cargar una vez más en otra orden). Después sigue cancelar la requisición. No se puede deshacer.'
    }
  };

  abrir(tipo: Dialogo): void {
    const o = this.orden();
    this.dialogo.set(tipo);
    this.errores.set({});
    this.motivo = '';
    this.observaciones = '';
    this.altaAbierta.set(false);
    this.proveedorId = tipo === 'proveedor' ? (o?.proveedor_id ?? null) : null;
    this.fecha = tipo === 'fecha' ? (o?.fecha ?? this.hoy) : this.hoy;
    if (tipo === 'generar' || tipo === 'proveedor') {
      this.servicio.proveedores().subscribe((p) => this.proveedores.set(p));
    }
    this.modal.open(this.tplDialogo(), { size: tipo === 'generar' || tipo === 'proveedor' ? 'lg' : 'md' });
  }

  ejecutar(ventana: NgbModalRef): void {
    const tipo = this.dialogo();
    const o = this.orden();
    let peticion: Observable<{ message: string; aviso?: string | null }>;

    switch (tipo) {
      case 'generar':
        peticion = this.servicio.generarOrden(this.d().requisicion.id, { proveedor_id: this.proveedorId, fecha: this.fecha, observaciones: this.observaciones });
        break;
      case 'proveedor':
        peticion = this.servicio.accionOrden(o!.id, 'proveedor', { proveedor_id: this.proveedorId });
        break;
      case 'fecha':
        peticion = this.servicio.accionOrden(o!.id, 'fecha', { fecha: this.fecha });
        break;
      case 'refacturar':
        peticion = this.servicio.accionOrden(o!.id, 'refacturar', { motivo: this.motivo });
        break;
      case 'generarClc':
        peticion = this.servicio.accionOrden(o!.id, 'clc');
        break;
      case 'cancelarClc':
        peticion = this.servicio.accionOrden(o!.id, 'cancelar-clc', { motivo: this.motivo });
        break;
      case 'cancelar':
        peticion = this.servicio.accionOrden(o!.id, 'cancelar', { motivo: this.motivo });
        break;
      default:
        return;
    }

    this.ocupado.set(true);
    this.errores.set({});
    peticion.subscribe({
      next: (r) => {
        this.ocupado.set(false);
        ventana.close();
        this.actualizado.emit({ tipo: 'success', texto: r.message, extra: r.aviso });
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errores.set(mensaje ? { _general: mensaje, ...campos } : campos);
      }
    });
  }

  /** Alta rápida (nombre y RFC) en el catálogo de proveedores; queda elegido. */
  darDeAlta(): void {
    this.errorAlta.set('');
    this.servicio.altaProveedor(this.nuevoNombre, this.nuevoRfc).subscribe({
      next: (r) => {
        this.servicio.proveedores().subscribe((p) => {
          this.proveedores.set(p);
          this.proveedorId = r.id;
          this.altaAbierta.set(false);
          this.nuevoNombre = '';
          this.nuevoRfc = '';
        });
      },
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.errorAlta.set(Object.values(campos).join(' ') || mensaje);
      }
    });
  }

  // --- Factura ---

  elegirXml(evento: Event): void {
    this.archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    this.errorXml.set('');
  }

  cargarXml(input: HTMLInputElement): void {
    const o = this.orden();
    if (!o || !this.archivo) {
      return;
    }
    this.ocupado.set(true);
    this.errorXml.set('');
    this.servicio.cargarXml(o.id, this.archivo).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.archivo = null;
        input.value = '';
        this.actualizado.emit({ tipo: 'success', texto: r.message, extra: r.aviso });
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errorXml.set(campos['xml'] ?? mensaje);
      }
    });
  }

  verificarSat(): void {
    const o = this.orden();
    if (!o) {
      return;
    }
    this.ocupado.set(true);
    this.servicio.accionOrden(o.id, 'verificar-sat').subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.actualizado.emit({ tipo: r.vigente ? 'success' : 'danger', texto: r.message, extra: r.aviso });
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        this.actualizado.emit({ tipo: 'danger', texto: erroresDe(e).mensaje });
      }
    });
  }

  /** Recursos Materiales asigna la orden al almacén para que reciba los bienes. */
  asignarAlmacen(): void {
    const o = this.orden()!;
    this.ocupado.set(true);
    this.almacen.asignar(o.id).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        this.actualizado.emit({ tipo: 'success', texto: r.message });
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.actualizado.emit({ tipo: 'danger', texto: campos['orden'] ?? mensaje });
      }
    });
  }

  imprimirClc(): void {
    const o = this.orden()!;
    this.servicio.pdfClc(o.id).subscribe({
      next: (blob) => abrirBlob(blob, `${o.clc}.pdf`),
      error: () => this.actualizado.emit({ tipo: 'danger', texto: 'No se pudo generar la CLC.' })
    });
  }

  imprimir(): void {
    const o = this.orden()!;
    this.servicio.pdfOrden(o.id).subscribe({
      next: (blob) => abrirBlob(blob, `${o.folio}.pdf`),
      error: () => this.actualizado.emit({ tipo: 'danger', texto: 'No se pudo generar la orden de compra.' })
    });
  }

  descargarXml(): void {
    const o = this.orden()!;
    this.servicio.descargarXml(o.id).subscribe({
      next: (blob) => abrirBlob(blob, `${o.folio}.xml`, true),
      error: () => this.actualizado.emit({ tipo: 'danger', texto: 'No se encontró el XML de la factura.' })
    });
  }

  claseSat(estado: string | null): string {
    return estado === 'Vigente' ? 'bg-light-success text-success' : estado ? 'bg-light-danger text-danger' : 'bg-light-secondary text-secondary';
  }
}
