import { Component, computed, inject, signal } from '@angular/core';
import { DatePipe, KeyValuePipe } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { erroresDe } from 'src/app/core/api';
import { centavos, money } from 'src/app/core/formato';
import { LineaModificacion, ModificacionesService, OpcionesModificacion } from './modificaciones.service';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';

type Tipo = 'traspaso' | 'reduccion' | 'ampliacion';
type Lado = 'origen' | 'destino';

/**
 * Solicitud de modificación presupuestal (SIEG: Create.tsx): línea emisora y receptora con su periodo,
 * importe y justificación. De cada lado se ve la fuente, el disponible del periodo y cómo queda; si el
 * emisor no alcanza, no deja registrar (el servidor lo vuelve a validar).
 */
@Component({
  selector: 'app-modificacion-form',
  imports: [SelectBusquedaDirective, FormsModule, RouterLink, KeyValuePipe, DatePipe],
  templateUrl: './modificacion-form.component.html'
})
export class ModificacionFormComponent {
  private servicio = inject(ModificacionesService);
  private router = inject(Router);

  readonly money = money;
  readonly sinOrden = () => 0;
  private readonly requisicionId = Number(inject(ActivatedRoute).snapshot.queryParamMap.get('requisicion')) || undefined;

  op = signal<OpcionesModificacion | null>(null);
  tipo = signal<Tipo>('traspaso');
  origen = signal<string>('');
  destino = signal<string>('');
  origenMes = signal<number | null>(null);
  destinoMes = signal<number | null>(null);
  importe = signal<number | null>(null);
  justificacion = signal('');
  buscarOrigen = signal('');
  buscarDestino = signal('');

  errores = signal<Record<string, string>>({});
  error = signal('');
  guardando = signal(false);

  usaOrigen = computed(() => this.tipo() !== 'ampliacion');
  usaDestino = computed(() => this.tipo() !== 'reduccion');

  private porClave = computed(() => new Map((this.op()?.lineas ?? []).map((l) => [l.clave, l])));
  lineaOrigen = computed(() => this.porClave().get(this.origen()));
  lineaDestino = computed(() => this.porClave().get(this.destino()));

  constructor() {
    this.servicio.opciones(this.requisicionId).subscribe((o) => {
      this.op.set(o);
      this.origenMes.set(o.mesActual);
      this.destinoMes.set(o.mesActual);
      // Desde una requisición sin suficiencia: la línea que falta como receptora y el faltante como importe.
      if (o.destinoSugerido) {
        this.destino.set(o.destinoSugerido);
        this.importe.set(o.importeSugerido);
        this.justificacion.set(`Suficiencia de la requisición ${o.requisicion?.folio}.`);
      }
    });
  }

  /** Líneas que coinciden con la búsqueda (hasta 150). El receptor de un traspaso, solo de la fuente del emisor. */
  opciones(lado: Lado): LineaModificacion[] {
    const texto = (lado === 'origen' ? this.buscarOrigen() : this.buscarDestino()).trim().toLowerCase().split(/\s+/).filter(Boolean);
    const fuente = lado === 'destino' && this.tipo() === 'traspaso' ? this.lineaOrigen()?.fuente : undefined;
    const elegida = lado === 'origen' ? this.origen() : this.destino();
    // La elegida va primero para que siga visible y marcada aunque la búsqueda deje más de 150.
    const actual = this.porClave().get(elegida);
    const lista = (this.op()?.lineas ?? []).filter((l) => {
      if (l.clave === elegida) {
        return false;
      }
      if (fuente && l.fuente !== fuente) {
        return false;
      }
      if (lado === 'destino' && l.clave === this.origen()) {
        return false;
      }
      const t = `${l.unidad_clave} ${l.unidad} ${l.fuente} ${l.proyecto} ${l.nombre_proyecto} ${l.partida} ${l.nombre_partida}`.toLowerCase();
      return texto.every((p) => t.includes(p));
    });
    return [...(actual ? [actual] : []), ...lista.slice(0, 150)];
  }

  etiqueta(l: LineaModificacion): string {
    return `${l.unidad_clave} · Fte ${l.fuente} · Proy ${l.proyecto} · ${l.partida} ${l.nombre_partida}`;
  }

  disponibleMes(clave: string | undefined, mes: number | null): number | null {
    return clave && mes ? (this.op()?.disponiblesMes[clave]?.[mes] ?? 0) : null;
  }

  /** Lo que le falta al emisor para cubrir el importe (anual o del mes), en pesos; 0 si alcanza. */
  faltante = computed(() => {
    const l = this.lineaOrigen();
    if (!this.usaOrigen() || !l || !this.importe()) {
      return 0;
    }
    const importe = centavos(this.importe());
    const delMes = centavos(this.disponibleMes(l.clave, this.origenMes()) ?? 0);
    return Math.max(0, importe - centavos(l.disponible), importe - delMes) / 100;
  });

  traspasoOtraFuente = computed(() => this.tipo() === 'traspaso' && !!this.lineaOrigen() && !!this.lineaDestino() && this.lineaOrigen()!.fuente !== this.lineaDestino()!.fuente);

  cambiarTipo(t: Tipo): void {
    this.tipo.set(t);
    this.errores.set({});
  }

  elegirOrigen(clave: string): void {
    this.origen.set(clave);
    // En un traspaso, si el receptor ya no es de la misma fuente, se quita.
    if (this.tipo() === 'traspaso' && this.lineaDestino() && this.lineaDestino()!.fuente !== this.lineaOrigen()?.fuente) {
      this.destino.set('');
    }
  }

  guardar(): void {
    this.guardando.set(true);
    this.errores.set({});
    this.error.set('');
    this.servicio
      .guardar({
        tipo: this.tipo(),
        origen: this.usaOrigen() ? this.origen() || null : null,
        destino: this.usaDestino() ? this.destino() || null : null,
        origen_mes: this.usaOrigen() ? this.origenMes() : null,
        destino_mes: this.usaDestino() ? this.destinoMes() : null,
        importe: this.importe(),
        justificacion: this.justificacion(),
        id_tb_egreso_requisicion: this.op()?.requisicion?.id ?? null
      })
      .subscribe({
        next: (r) => this.router.navigate(['/modificaciones', r.id], { state: { mensaje: r.message } }),
        error: (e: HttpErrorResponse) => {
          const { campos, mensaje } = erroresDe(e);
          this.errores.set(campos);
          this.error.set(mensaje || (campos['tipo'] ?? ''));
          this.guardando.set(false);
        }
      });
  }
}
