// angular import
import { Component, DestroyRef, output, inject, input, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { Router, RouterModule } from '@angular/router';

// project import
import { SharedModule } from 'src/app/theme/shared/shared.module';
import { AuthService } from 'src/app/core/auth/auth.service';
import { Notificacion, TicketsService } from 'src/app/pages/tickets/tickets.service';

// icon
import { IconService } from '@ant-design/icons-angular';
import { BellOutline, LogoutOutline, UserOutline } from '@ant-design/icons-angular/icons';

/** Cada cuánto se consultan las notificaciones (tickets de soporte). */
const CADA_MS = 60_000;

@Component({
  selector: 'app-nav-right',
  imports: [SharedModule, RouterModule, DatePipe],
  templateUrl: './nav-right.component.html',
  styleUrls: ['./nav-right.component.scss']
})
export class NavRightComponent {
  private iconService = inject(IconService);
  private tickets = inject(TicketsService);
  private router = inject(Router);
  protected auth = inject(AuthService);

  // public props
  styleSelectorToggle = input<boolean>();
  readonly Customize = output();

  notificaciones = signal<Notificacion[]>([]);

  // constructor
  constructor() {
    this.iconService.addIcon(...[LogoutOutline, UserOutline, BellOutline]);

    if (this.auth.can('tickets-index')) {
      this.consultar();
      const temporizador = setInterval(() => this.consultar(), CADA_MS);
      inject(DestroyRef).onDestroy(() => clearInterval(temporizador));
    }
  }

  private consultar(): void {
    if (!this.auth.isAuthenticated()) {
      return;
    }
    this.tickets.notificaciones().subscribe({ next: (r) => this.notificaciones.set(r.unread), error: () => undefined });
  }

  /** Marca la notificación como leída y abre su ticket. */
  abrir(n: Notificacion): void {
    this.tickets.marcarLeida(n.id).subscribe(() => {
      this.notificaciones.update((lista) => lista.filter((x) => x.id !== n.id));
      if (n.ticket_id) {
        this.router.navigate(['/tickets', n.ticket_id]);
      }
    });
  }

  /** Iniciales para el avatar: "JUAN PÉREZ" → "JP". */
  iniciales(nombre: string): string {
    return nombre
      .split(/\s+/)
      .filter((p) => p && !p.endsWith('.'))
      .slice(0, 2)
      .map((p) => p[0])
      .join('')
      .toUpperCase();
  }
}
