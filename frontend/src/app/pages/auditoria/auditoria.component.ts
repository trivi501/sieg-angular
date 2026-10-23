import { Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { HttpClient, HttpParams } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';
import { PaginacionComponent } from '../admin/paginacion.component';
import { SelectBusquedaDirective } from 'src/app/shared/select-busqueda.directive';
import { AccionComponent } from 'src/app/shared/accion.component';

type Valores = Record<string, unknown> | null;

interface Movimiento {
  id: number;
  fecha: string | null;
  usuario: string | null;
  accion: string;
  tipo: string;
  auditable_type: string;
  registro: string;
  catalogo: string | null;
  enlace: string | null;
  etiquetas: Record<string, string>;
  descripcion: string | null;
  lote: string | null;
  antes: Valores;
  despues: Valores;
  ip: string | null;
}

interface Respuesta extends Paginado<Movimiento> {
  tipos: { valor: string; etiqueta: string }[];
  usuarios: { id: number; name: string }[];
  acciones: string[];
}

const FILTROS_VACIOS = { tipo: '', usuario: '', accion: '', desde: '', hasta: '', lote: '', registro: '' };

/**
 * Bitácora de auditoría de todo el sistema (SIEG: auditoria/Index.tsx): altas, modificaciones, bajas,
 * reactivaciones, eliminaciones y accesos, con usuario, IP, fecha y valores anterior / nuevo.
 */
@Component({
  selector: 'app-auditoria',
  imports: [AccionComponent, SelectBusquedaDirective, FormsModule, RouterLink, DatePipe, PaginacionComponent],
  templateUrl: './auditoria.component.html',
  styles: `
    td,
    th {
      font-size: 12.5px;
    }
    .cambios td {
      font-size: 12px;
      padding: 2px 6px;
    }
  `
})
export class AuditoriaComponent {
  private http = inject(HttpClient);

  datos = signal<Respuesta | null>(null);
  filtros: Record<string, string> = { ...FILTROS_VACIOS };
  abiertos = signal(new Set<number>());

  constructor() {
    this.cargar(1);
  }

  cargar(page: number): void {
    let params = new HttpParams().set('page', page);
    for (const [k, v] of Object.entries(this.filtros)) {
      if (v) {
        params = params.set(k, v);
      }
    }
    this.http.get<Respuesta>(`${environment.apiUrl}/auditoria`, { params }).subscribe((d) => this.datos.set(d));
  }

  limpiar(): void {
    this.filtros = { ...FILTROS_VACIOS };
    this.cargar(1);
  }

  hayFiltros(): boolean {
    return Object.values(this.filtros).some((v) => !!v);
  }

  /** "Registro #": todo lo de ese registro (mismo módulo e id). */
  verRegistro(m: Movimiento): void {
    this.filtros = { ...FILTROS_VACIOS, tipo: m.auditable_type, registro: m.registro };
    this.cargar(1);
  }

  /** Toda una importación o borrado masivo. */
  verLote(m: Movimiento): void {
    this.filtros = { ...FILTROS_VACIOS, lote: m.lote ?? '' };
    this.cargar(1);
  }

  alternar(id: number): void {
    this.abiertos.update((s) => {
      const n = new Set(s);
      if (n.has(id)) {
        n.delete(id);
      } else {
        n.add(id);
      }
      return n;
    });
  }

  claseAccion(accion: string): string {
    return (
      {
        alta: 'bg-light-success text-success',
        modificación: 'bg-light-primary text-primary',
        baja: 'bg-light-warning text-warning',
        reactivación: 'bg-light-info text-info',
        eliminación: 'bg-light-danger text-danger',
        'inicio de sesión': 'bg-light-secondary text-secondary',
        'cierre de sesión': 'bg-light-secondary text-secondary',
        'acceso fallido': 'bg-danger text-white'
      }[accion] ?? 'bg-light-secondary text-secondary'
    );
  }

  /** Campos que cambiaron, con su nombre legible si lo hay. */
  campos(m: Movimiento): { campo: string; etiqueta: string; antes: string; despues: string }[] {
    const nombres = new Set([...Object.keys(m.antes ?? {}), ...Object.keys(m.despues ?? {})]);
    return [...nombres].map((campo) => ({
      campo,
      etiqueta: m.etiquetas[campo] ?? campo,
      antes: this.texto(m.antes?.[campo]),
      despues: this.texto(m.despues?.[campo])
    }));
  }

  private texto(v: unknown): string {
    if (v === null || v === undefined || v === '') {
      return '—';
    }
    if (typeof v === 'boolean') {
      return v ? 'Sí' : 'No';
    }
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
  }
}
