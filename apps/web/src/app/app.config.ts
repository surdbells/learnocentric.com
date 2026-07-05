import { APP_INITIALIZER, ApplicationConfig, isDevMode, provideBrowserGlobalErrorListeners, provideZonelessChangeDetection } from '@angular/core';
import { provideRouter } from '@angular/router';

import { routes } from './app.routes';
import { provideClientHydration, withEventReplay } from '@angular/platform-browser';
import { provideToastr } from 'ngx-toastr';
import { provideAnimations } from '@angular/platform-browser/animations';
import { provideHttpClient, withInterceptors, withFetch } from '@angular/common/http';
import { authInterceptor } from './common/auth/auth.interceptor';
import { AuthService } from './common/auth/auth.service';
import { API_BASE_URL } from "./common/service/api.service";

export function initAuth(auth: AuthService) {
  return () => auth.initFromStorage();
}

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZonelessChangeDetection(),
    provideRouter(routes),
    provideClientHydration(withEventReplay()),
    provideToastr(),
    provideAnimations(),
    provideHttpClient(withFetch(), withInterceptors([authInterceptor])),
    { provide: APP_INITIALIZER, useFactory: initAuth, deps: [AuthService], multi: true },
    // In dev the browser uses a relative base ('') so requests hit the Angular dev-server
    // proxy (see proxy.conf.json) and avoid CORS. In a production build it targets the API
    // directly. The SSR server overrides this with the absolute URL (app.config.server.ts).
    { provide: API_BASE_URL, useValue: isDevMode() ? '' : 'https://learnocentric.com' },
  ]
};
