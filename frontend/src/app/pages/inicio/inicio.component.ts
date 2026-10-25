import { Component, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { RouterLink } from '@angular/router';

import { environment } from 'src/environments/environment';
import { AuthService } from 'src/app/core/auth/auth.service';
import { money } from 'src/app/core/formato';

interface Resumen {
  verPresupuesto: boolean;
  totales: Record<string, number>;
  lineas: number;
  lineasSinDisponible: number;
  pendientes: { pendientes: number; en_revision: number; recursos_materiales: number; sin_orden_compra: number; modificaciones: number };
}

/** Indicadores por área (cada bloque viene null si el usuario no tiene permiso). */
interface Panel {
  resumen: Resumen | null;
  clc: {
    en_tramite: { cantidad: number; importe: number };
    por_area: { area: string; nombre: string; cantidad: number; importe: number }[];
    facturas_sin_clc: { cantidad: number; importe: number };
    tramites_por_validar: { tipo: string; nombre: string; cantidad: number }[];
  } | null;
  pagos: {
    mes: { nombre: string; cantidad: number; importe: number };
    año: { año: number; cantidad: number; importe: number };
    pasivo: { clc_por_pagar: number; facturas_sin_clc: number; total: number; cantidad: number };
    en_transito: { cantidad: number; importe: number } | null;
  } | null;
  combustible: {
    año: { año: number; litros: number; importe: number };
    mes: { nombre: string; litros: number; importe: number };
    secretarias: { secretaria: string; litros: number; importe: number }[];
    bitacoras_pendientes: number;
  } | null;
  mantenimiento: {
    año: number;
    servicios: number;
    en_taller: number;
    preventivos: number;
    correctivos: number;
    costo: number;
    pagado: number;
    secretarias: { secretaria: string; servicios: number; costo: number }[];
  } | null;
}

/** Momentos del presupuesto que muestra el panel, con su explicación (SIEG: dashboard.tsx). */
const MOMENTOS = [
  { key: 'asignado', titulo: 'Aprobado', ayuda: 'Presupuesto aprobado (importado)' },
  { key: 'modificado', titulo: 'Modificado', ayuda: 'Modificaciones autorizadas' },
  { key: 'vigente', titulo: 'Vigente', ayuda: 'Aprobado ± modificado' },
  { key: 'comprometido', titulo: 'Comprometido', ayuda: 'Acumulado: todo lo comprometido (incluye devengado y pagado)' },
  { key: 'en_tramite', titulo: 'En trámite', ayuda: 'Cotizadas sin suficiencia' },
  { key: 'devengado', titulo: 'Devengado', ayuda: 'Acumulado: con factura, incluye lo ejercido y pagado' },
  { key: 'ejercido', titulo: 'Ejercido', ayuda: 'Acumulado: con CLC emitida, incluye lo pagado' },
  { key: 'pagado', titulo: 'Pagado', ayuda: 'Con pago registrado' },
  { key: 'disponible', titulo: 'Disponible', ayuda: 'Vigente − comprometido (acumulado)' }
];

/**
 * Panel de inicio (SIEG: DashboardController y dashboard.tsx): resumen del presupuesto de las unidades
 * del usuario y lo pendiente en cada etapa; cada tarjeta lleva a su listado.
 */
@Component({
  selector: 'app-inicio',
  imports: [RouterLink],
  templateUrl: './inicio.component.html'
})
export class InicioComponent {
  private http = inject(HttpClient);
  protected auth = inject(AuthService);

  readonly money = money;
  readonly momentos = MOMENTOS;

  /** undefined = cargando; null = sin acceso a requisiciones ni presupuesto. */
  resumen = signal<Resumen | null | undefined>(undefined);
  panel = signal<Panel | null>(null);
  readonly litros = (n: number) => new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(n);

  /** Solo las tarjetas de las pantallas a las que el usuario tiene acceso. */
  tarjetas = computed(() => {
    const p = this.resumen()?.pendientes;
    if (!p) {
      return [];
    }
    return [
      {
        titulo: 'Requisiciones por enviar',
        valor: p.pendientes,
        ruta: '/requisiciones',
        query: { estado: 1 },
        ayuda: 'Pendientes o rechazadas'
      },
      { titulo: 'En revisión', valor: p.en_revision, ruta: '/requisiciones', query: { estado: 2 }, ayuda: 'Esperan validación' },
      {
        titulo: 'En Recursos Materiales',
        valor: p.recursos_materiales,
        ruta: '/recursos-materiales',
        query: {},
        permiso: 'recursos-materiales-index',
        ayuda: 'Por recibir, cotizar o validar suficiencia'
      },
      {
        titulo: 'Con suficiencia sin orden de compra',
        valor: p.sin_orden_compra,
        ruta: '/recursos-materiales',
        query: {},
        permiso: 'recursos-materiales-index',
        ayuda: 'Listas para comprar'
      },
      {
        titulo: 'Modificaciones por autorizar',
        valor: p.modificaciones,
        ruta: '/modificaciones',
        query: { estado: 'pendiente' },
        permiso: 'modificaciones-index',
        ayuda: 'Traspasos, reducciones y ampliaciones'
      }
    ].filter((t) => !t.permiso || this.auth.can(t.permiso));
  });

  constructor() {
    this.http.get<Panel>(`${environment.apiUrl}/panel`).subscribe({
      next: (r) => {
        this.panel.set(r);
        this.resumen.set(r.resumen);
      },
      error: () => this.resumen.set(null)
    });
  }
}
