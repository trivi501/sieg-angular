import { enableProdMode, importProvidersFrom, inject, provideAppInitializer } from '@angular/core';
import { provideHttpClient, withInterceptors } from '@angular/common/http';

import { environment } from './environments/environment';
import { BrowserModule, bootstrapApplication } from '@angular/platform-browser';
import { AppRoutingModule } from './app/app-routing.module';
import { AppComponent } from './app/app.component';
import { authInterceptor } from './app/core/auth/auth.interceptor';
import { AuthService } from './app/core/auth/auth.service';
import { loadingInterceptor } from './app/core/loading.service';

if (environment.production) {
  enableProdMode();
}

bootstrapApplication(AppComponent, {
  providers: [
    importProvidersFrom(BrowserModule, AppRoutingModule),
    provideHttpClient(withInterceptors([authInterceptor, loadingInterceptor])),
    // Recupera la sesión antes de evaluar los guards de la primera ruta.
    provideAppInitializer(() => inject(AuthService).restaurar())
  ]
}).catch((err) => console.error(err));
