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

/** Momentos del presupuesto que muestra el panel, con su explicación (SIEG: dashboard.tsx). */
const MOMENTOS = [
  { key: 'asignado', titulo: 'Aprobado', ayuda: 'Presupuesto aprobado (importado)' },
  { key: 'modificado', titulo: 'Modificado', ayuda: 'Modificaciones autorizadas' },
  { key: 'vigente', titulo: 'Vigente', ayuda: 'Aprobado ± modificado' },
  { key: 'comprometido', titulo: 'Comprometido', ayuda: 'Acumulado: todo lo comprometido (incluye devengado y pagado)' },
  { key: 'en_tramite', titulo: 'En trámite', ayuda: 'Cotizadas sin suficiencia' },
  { key: 'devengado', titulo: 'Devengado', ayuda: 'Acumulado: con factura (XML), incluye lo pagado' },
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

  /** Solo las tarjetas de las pantallas a las que el usuario tiene acceso. */
  tarjetas = computed(() => {
    const p = this.resumen()?.pendientes;
    if (!p) {
      return [];
    }
    return [
      { titulo: 'Requisiciones por enviar', valor: p.pendientes, ruta: '/requisiciones', query: { estado: 1 }, ayuda: 'Pendientes o rechazadas' },
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
    this.http.get<{ resumen: Resumen | null }>(`${environment.apiUrl}/panel`).subscribe({
      next: (r) => this.resumen.set(r.resumen),
      error: () => this.resumen.set(null)
    });
  }
}
