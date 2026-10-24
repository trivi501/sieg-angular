import { Component, HostListener, inject, output, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { IconDirective, IconService } from '@ant-design/icons-angular';

import { erroresDe } from 'src/app/core/api';
import { ICONOS_ACCION } from 'src/app/shared/accion.component';
import { DetalleRequisicion, RequisicionesService, abrirBlob } from './requisiciones.service';

/** Opción del menú: navega, descarga o ejecuta algo; "grupo" separa las secciones. */
interface Opcion {
  texto: string;
  icono: string;
  grupo: 'ver' | 'documentos' | 'tramite' | 'orden' | 'peligro';
  peligro?: boolean;
  hacer: () => void;
}

/**
 * Menú contextual (clic derecho) de una fila de requisiciones. Pide al servidor qué puede hacer el usuario
 * con esa requisición (los mismos permisos del detalle) y ofrece esas acciones. Las que necesitan
 * confirmar o capturar algo abren el detalle con su ventana ya abierta (?accion=).
 */
@Component({
  selector: 'app-menu-requisicion',
  imports: [IconDirective],
  template: `
    @if (abierto(); as a) {
      <div class="dropdown-menu show shadow menu-contextual" [style.left.px]="a.x" [style.top.px]="a.y" (click)="$event.stopPropagation()" role="menu">
        <h6 class="dropdown-header">{{ a.folio }}</h6>
        @if (cargando()) {
          <span class="dropdown-item-text small text-muted">Cargando acciones…</span>
        } @else if (error()) {
          <span class="dropdown-item-text small text-danger">{{ error() }}</span>
        } @else {
          @for (o of opciones(); track o.texto; let i = $index) {
            @if (i > 0 && opciones()[i - 1].grupo !== o.grupo) {
              <div class="dropdown-divider"></div>
            }
            <button type="button" class="dropdown-item d-flex align-items-center gap-2" [class.text-danger]="o.peligro" (click)="elegir(o)" role="menuitem">
              <i antIcon [type]="o.icono" theme="outline"></i>{{ o.texto }}
            </button>
          }
        }
      </div>
    }
  `,
  styles: `
    .menu-contextual {
      position: fixed;
      z-index: 1080;
      min-width: 240px;
      max-height: 80vh;
      overflow-y: auto;
      font-size: 13px;
    }
  `
})
export class MenuRequisicionComponent {
  private servicio = inject(RequisicionesService);
  private router = inject(Router);

  /** Mensaje para la lista (resultado de eliminar, errores de descarga). */
  readonly aviso = output<{ tipo: 'success' | 'danger'; texto: string }>();
  /** La lista se recarga (p. ej. tras eliminar). */
  readonly cambio = output<void>();

  abierto = signal<{ id: number; folio: string; x: number; y: number } | null>(null);
  cargando = signal(false);
  error = signal('');
  opciones = signal<Opcion[]>([]);

  constructor() {
    inject(IconService).addIcon(...ICONOS_ACCION);
  }

  /** Abre el menú en la posición del clic para la requisición indicada. */
  abrir(evento: MouseEvent, id: number, folio: string): void {
    evento.preventDefault();
    // Que no se salga de la pantalla (el menú mide ~250 × hasta 80vh).
    const x = Math.min(evento.clientX, window.innerWidth - 260);
    const y = Math.min(evento.clientY, window.innerHeight - 120);
    this.abierto.set({ id, folio, x, y });
    this.cargando.set(true);
    this.error.set('');
    this.opciones.set([]);
    this.servicio.detalle(id).subscribe({
      next: (d) => {
        if (this.abierto()?.id !== id) return;
        this.opciones.set(this.armar(d));
        this.cargando.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.cargando.set(false);
        this.error.set(erroresDe(e).mensaje || 'No se pudieron cargar las acciones.');
      }
    });
  }

  cerrar(): void {
    this.abierto.set(null);
  }

  @HostListener('document:click')
  @HostListener('document:keydown.escape')
  @HostListener('window:scroll')
  @HostListener('window:resize')
  alSalir(): void {
    this.cerrar();
  }

  elegir(o: Opcion): void {
    this.cerrar();
    o.hacer();
  }

  private armar(d: DetalleRequisicion): Opcion[] {
    const id = d.requisicion.id;
    const folio = d.requisicion.folio;
    const p = d.puede;
    const oc = d.orden;
    const ir = (accion?: string) => this.router.navigate(['/requisiciones', id], accion ? { queryParams: { accion } } : {});
    const op: Opcion[] = [];
    const agregar = (cond: unknown, texto: string, icono: string, grupo: Opcion['grupo'], hacer: () => void, peligro = false) => {
      if (cond) op.push({ texto, icono, grupo, hacer, peligro });
    };

    agregar(true, 'Ver detalle', 'eye', 'ver', () => ir());
    agregar(true, 'Abrir en otra pestaña', 'export', 'ver', () => window.open(`/requisiciones/${id}`, '_blank'));
    agregar(p.editar, 'Editar', 'edit', 'ver', () => this.router.navigate(['/requisiciones', id, 'editar']));
    agregar(p.cotizar, 'Capturar cotización', 'dollar', 'ver', () => this.router.navigate(['/requisiciones', id, 'editar']));

    agregar(p.imprimir, 'Imprimir formato (FUR)', 'printer', 'documentos', () =>
      this.descargar(this.servicio.pdf(id), `FUR-${folio}.pdf`)
    );
    agregar(p.imprimirOrden && oc, 'Imprimir orden de compra', 'file-pdf', 'documentos', () =>
      this.descargar(this.servicio.pdfOrden(oc!.id), `${oc!.folio}.pdf`)
    );
    agregar(p.imprimirClc && oc, 'Imprimir CLC', 'file-pdf', 'documentos', () => this.descargar(this.servicio.pdfClc(oc!.id), `${oc!.clc ?? oc!.folio}.pdf`));
    agregar(p.descargarXml && oc, 'Descargar factura (XML)', 'download', 'documentos', () =>
      this.descargar(this.servicio.descargarXml(oc!.id), `${oc!.folio}.xml`, true)
    );

    agregar(p.extemporanea, 'Autorizar extemporánea', 'check-circle', 'tramite', () => ir('extemporanea'));
    agregar(p.enviar, 'Enviar a revisión', 'send', 'tramite', () => ir('enviar'));
    agregar(p.validar, 'Válida', 'check', 'tramite', () => ir('aprobar'));
    agregar(p.validar, 'No válida', 'close', 'tramite', () => ir('rechazar'));
    agregar(p.enviarRecursos, d.mantenimiento ? 'Enviar al taller para cotizar' : 'Enviar a Recursos Materiales', 'send', 'tramite', () => ir('recursos'));
    agregar(p.recibir, 'Recibido: iniciar cotización', 'inbox', 'tramite', () => ir('recibir'));
    agregar(p.suficiencia, 'Validar suficiencia', 'calculator', 'tramite', () => ir('suficiencia'));
    agregar(p.devolver, 'Regresar al área', 'rollback', 'tramite', () => ir('devolver'));
    agregar(p.cambiarFecha, 'Cambiar fecha de trámite', 'calendar', 'tramite', () => ir('fecha'));
    agregar(p.modificacion, 'Solicitar modificación presupuestal', 'swap', 'tramite', () =>
      this.router.navigate(['/modificaciones/nueva'], { queryParams: { requisicion: id } })
    );

    agregar(p.generarOrden, 'Generar orden de compra', 'file-add', 'orden', () => ir('oc:generar'));
    agregar(p.cambiarProveedor, 'Cambiar proveedor', 'user-switch', 'orden', () => ir('oc:proveedor'));
    agregar(p.cargarXml, 'Subir factura (XML)', 'upload', 'orden', () => ir('oc:xml'));
    agregar(p.generarClc, 'Generar CLC', 'file-done', 'orden', () => ir('oc:generarClc'));
    agregar(p.verAlmacen && oc, 'Ver en almacén', 'shop', 'orden', () => this.router.navigate(['/almacen', oc!.id]));

    agregar(p.cancelar, 'Cancelar requisición', 'stop', 'peligro', () => ir('cancelar'), true);
    agregar(p.eliminar, 'Eliminar', 'delete', 'peligro', () => this.eliminar(id), true);
    return op;
  }

  private descargar(peticion: import('rxjs').Observable<Blob>, nombre: string, guardar = false): void {
    peticion.subscribe({
      next: (b) => abrirBlob(b, nombre, guardar),
      error: () => this.aviso.emit({ tipo: 'danger', texto: `No se pudo obtener ${nombre}.` })
    });
  }

  private eliminar(id: number): void {
    if (!confirm('¿Eliminar esta requisición? Se borra con sus conceptos (queda en la bitácora de auditoría).')) {
      return;
    }
    this.servicio.eliminar(id).subscribe({
      next: () => {
        this.aviso.emit({ tipo: 'success', texto: 'Requisición eliminada.' });
        this.cambio.emit();
      },
      error: (e: HttpErrorResponse) => this.aviso.emit({ tipo: 'danger', texto: erroresDe(e).mensaje })
    });
  }
}
