import { Component, TemplateRef, computed, inject, signal, viewChild } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { NgbModal, NgbModalRef } from '@ng-bootstrap/ng-bootstrap';

import { erroresDe } from 'src/app/core/api';
import { money } from 'src/app/core/formato';
import { DetalleRequisicion, RequisicionesService, abrirBlob, claseEstado } from './requisiciones.service';
import { AvisoOrden, OrdenCompraComponent } from './orden-compra.component';
import { AccionComponent } from 'src/app/shared/accion.component';

/** Acción del trámite que se confirma en el diálogo. */
interface Accion {
  ruta: string;
  titulo: string;
  texto: string;
  boton: string;
  clase: string;
  motivo?: string;
  fecha?: boolean;
  /** A dónde ir al terminar (p. ej. la bandeja al regresar al área). */
  despues?: string;
}

/** Una requisición: datos, conceptos, suficiencia, orden de compra (solo lectura), bitácora y acciones (SIEG: Show.tsx). */
@Component({
  selector: 'app-requisicion-detalle',
  imports: [AccionComponent, RouterLink, FormsModule, DatePipe, OrdenCompraComponent],
  templateUrl: './requisicion-detalle.component.html',
  styles: `
    td,
    th {
      font-size: 13px;
    }
  `
})
export class RequisicionDetalleComponent {
  private servicio = inject(RequisicionesService);
  private modal = inject(NgbModal);
  private router = inject(Router);

  private tplAccion = viewChild.required<TemplateRef<unknown>>('accionTpl');

  readonly money = money;
  readonly claseEstado = claseEstado;
  private readonly ruta = inject(ActivatedRoute);
  readonly id = Number(this.ruta.snapshot.paramMap.get('id'));
  /** Acción pedida desde el menú contextual de la lista (?accion=): se abre una vez al cargar. */
  private accionPendiente = this.ruta.snapshot.queryParamMap.get('accion');
  /** La de la orden de compra (?accion=oc:…) la abre el componente de la orden. */
  accionOrden = signal<string | null>(null);

  d = signal<DetalleRequisicion | null>(null);
  error = signal('');
  /** El alta y la edición llegan con su mensaje en el estado de la navegación. */
  mensaje = signal<{ tipo: 'success' | 'danger' | 'warning'; texto: string } | null>(
    history.state?.mensaje ? { tipo: history.state.tipo ?? 'success', texto: history.state.mensaje as string } : null
  );

  /** Aviso adicional de la orden de compra (emisor en la lista de EFOS). */
  aviso = signal<string | null>(null);

  // Diálogo de acción
  accion = signal<Accion | null>(null);
  motivo = '';
  fecha = '';
  errorAccion = signal('');
  ocupado = signal(false);

  algunaAccion = computed(() => Object.values(this.d()?.puede ?? {}).some((v) => v));

  constructor() {
    this.cargar();
  }

  cargar(): void {
    this.servicio.detalle(this.id).subscribe({
      next: (d) => {
        this.d.set(d);
        this.abrirAccionPendiente();
      },
      error: (e: HttpErrorResponse) =>
        this.error.set(e.status === 404 ? 'La requisición no existe.' : e.status === 403 ? erroresDe(e).mensaje || 'No tienes acceso a esta requisición.' : erroresDe(e).mensaje)
    });
  }

  private abrirAccionPendiente(): void {
    const accion = this.accionPendiente;
    if (!accion) {
      return;
    }
    this.accionPendiente = null;
    // Se quita de la URL para que al recargar no se vuelva a abrir.
    this.router.navigate([], { relativeTo: this.ruta, queryParams: {}, replaceUrl: true });
    if (accion.startsWith('oc:')) {
      this.accionOrden.set(accion.slice(3));
    } else if (this.acciones[accion]) {
      setTimeout(() => this.abrir(accion));
    }
  }

  nombre(tipo: 'fuentes' | 'proyectos' | 'partidas', clave: string): string {
    return this.d()?.nombres[tipo]?.[clave] ?? '';
  }

  // --- Acciones ---

  private readonly acciones: Record<string, Omit<Accion, 'motivo'> & { conMotivo?: boolean }> = {
    enviar: { ruta: 'enviar', titulo: 'Enviar a revisión', texto: 'La requisición ya no se podrá modificar mientras está en revisión.', boton: 'Enviar a revisión', clase: 'btn-primary' },
    aprobar: { ruta: 'aprobar', titulo: 'Validar requisición', texto: 'La requisición queda aprobada y se puede imprimir el formato para la firma del titular.', boton: 'Válida', clase: 'btn-primary' },
    rechazar: { ruta: 'rechazar', titulo: 'Requisición no válida', texto: 'Regresa al área para que la corrija. El motivo queda en la bitácora.', boton: 'No válida', clase: 'btn-danger', conMotivo: true },
    recursos: {
      ruta: 'recursos-materiales',
      titulo: 'Enviar a Recursos Materiales',
      texto: 'Confirma que el formato ya está impreso y firmado por el titular.',
      boton: 'Enviar a Recursos Materiales',
      clase: 'btn-primary'
    },
    recibir: { ruta: 'recibido', titulo: 'Recibir requisición', texto: 'Recursos Materiales la recibe e inicia la cotización.', boton: 'Recibido - iniciar cotización', clase: 'btn-primary' },
    devolver: {
      ruta: 'devolver-area',
      titulo: 'Regresar al área',
      texto: 'Queda como No válida para que el área la modifique y la vuelva a enviar (p. ej. un producto sin stock).',
      boton: 'Regresar al área',
      clase: 'btn-warning',
      conMotivo: true,
      despues: '/recursos-materiales'
    },
    suficiencia: {
      ruta: 'suficiencia',
      titulo: 'Validar suficiencia presupuestal',
      texto: 'Se compara lo cotizado contra el disponible de cada partida. Si alcanza, la requisición queda comprometida.',
      boton: 'Validar suficiencia',
      clase: 'btn-primary'
    },
    extemporanea: {
      ruta: 'autorizar-extemporanea',
      titulo: 'Autorizar requisición extemporánea',
      texto: 'Se capturó fuera del periodo. Al autorizarla se envía sola a revisión; queda registrado quién y cuándo.',
      boton: 'Autorizar',
      clase: 'btn-primary'
    },
    cancelar: {
      ruta: 'cancelar',
      titulo: 'Cancelar requisición',
      texto: 'Deja de contar en el presupuesto (en trámite o comprometido) y ya no se imprime. No se puede deshacer.',
      boton: 'Cancelar requisición',
      clase: 'btn-danger',
      conMotivo: true
    },
    fecha: { ruta: 'fecha', titulo: 'Cambiar fecha de trámite', texto: 'Es la fecha que imprime el formato. Queda en la bitácora.', boton: 'Guardar fecha', clase: 'btn-primary', fecha: true },
    clcApoyo: {
      ruta: 'clc',
      titulo: 'Generar CLC del apoyo',
      texto: 'Apoyo sin factura: se genera la CLC a favor del beneficiario (sin orden de compra ni factura). Después se envía en el Trámite de CLC.',
      boton: 'Generar CLC',
      clase: 'btn-primary'
    }
  };

  abrir(clave: string): void {
    const a = this.acciones[clave];
    this.motivo = '';
    this.fecha = this.d()?.requisicion.solicitado ?? '';
    this.errorAccion.set('');
    this.accion.set({ ...a, motivo: a.conMotivo ? '' : undefined });
    this.modal.open(this.tplAccion(), { size: 'md' });
  }

  ejecutar(ventana: NgbModalRef): void {
    const a = this.accion();
    if (!a) {
      return;
    }
    const datos = a.motivo !== undefined ? { motivo: this.motivo } : a.fecha ? { fecha: this.fecha } : {};

    this.ocupado.set(true);
    this.errorAccion.set('');
    this.servicio.accion(this.id, a.ruta, datos).subscribe({
      next: (r) => {
        this.ocupado.set(false);
        ventana.close();
        if (a.despues) {
          this.router.navigateByUrl(a.despues);
          return;
        }
        this.mensaje.set({ tipo: r.suficiente === false ? 'danger' : 'success', texto: r.message });
        this.cargar();
      },
      error: (e: HttpErrorResponse) => {
        this.ocupado.set(false);
        const { campos, mensaje } = erroresDe(e);
        this.errorAccion.set(campos['motivo'] ?? campos['fecha'] ?? mensaje);
      }
    });
  }

  ordenActualizada(aviso: AvisoOrden): void {
    this.mensaje.set({ tipo: aviso.tipo, texto: aviso.texto });
    this.aviso.set(aviso.extra ?? null);
    this.cargar();
  }

  eliminar(): void {
    if (!confirm('¿Eliminar esta requisición? Se borra con sus conceptos (queda en la bitácora de auditoría).')) {
      return;
    }
    this.servicio.eliminar(this.id).subscribe({
      next: () => this.router.navigate(['/requisiciones']),
      error: (e: HttpErrorResponse) => this.mensaje.set({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }

  imprimir(): void {
    const folio = this.d()?.requisicion.folio ?? this.id;
    this.servicio.pdf(this.id).subscribe({
      next: (blob) => abrirBlob(blob, `FUR-${folio}.pdf`),
      error: () => this.mensaje.set({ tipo: 'danger', texto: 'No se pudo generar el formato.' })
    });
  }
}
