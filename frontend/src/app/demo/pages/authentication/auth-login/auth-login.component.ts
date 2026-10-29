// project import
import { Component, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';

import { AuthService } from 'src/app/core/auth/auth.service';
import { erroresDe } from 'src/app/core/api';

@Component({
  selector: 'app-auth-login',
  imports: [RouterModule, ReactiveFormsModule],
  templateUrl: './auth-login.component.html',
  styleUrl: './auth-login.component.scss'
})
export class AuthLoginComponent {
  private auth = inject(AuthService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);

  form = inject(FormBuilder).nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', Validators.required]
  });

  enviando = signal(false);
  error = signal('');
  /** Por qué se cerró la sesión (inactividad o token vencido), para avisarlo. */
  readonly motivo = this.route.snapshot.queryParamMap.get('motivo');

  entrar(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.enviando.set(true);
    this.error.set('');
    const { email, password } = this.form.getRawValue();

    this.auth.login(email, password).subscribe({
      next: () => this.router.navigateByUrl(this.route.snapshot.queryParamMap.get('volver') || '/inicio'),
      error: (e: HttpErrorResponse) => {
        const { campos, mensaje } = erroresDe(e);
        this.error.set(campos['email'] ?? campos['password'] ?? mensaje);
        this.enviando.set(false);
      }
    });
  }
}
