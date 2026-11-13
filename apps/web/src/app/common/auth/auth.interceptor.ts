import { inject } from '@angular/core';
import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { AuthStore } from './auth.store';
import { API_BASE_URL } from '../service/api.service';
import { catchError, throwError } from 'rxjs';
import { Router } from '@angular/router';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const store = inject(AuthStore);
  const token = store.token();
  const baseUrl = (inject(API_BASE_URL, { optional: true }) ?? '').replace(/\/+$/, '');

  // Raw HttpClient calls (e.g. file uploads with progress) pass a relative
  // "/backend/..." URL. In production the SPA and API are separate origins, so
  // prefix the API base — otherwise the request hits the SPA host and 405s.
  const url = (baseUrl && req.url.startsWith('/backend')) ? baseUrl + req.url : req.url;

  const authReq = req.clone({
    url,
    withCredentials: true,
    setHeaders: token ? { Authorization: `Bearer ${token}` } : {}
  });

  const router = inject(Router);

  return next(authReq).pipe(
    catchError((error: HttpErrorResponse) => {
      // The platform requires 2FA for staff and this account hasn't enrolled yet.
      if (error?.status === 403 && (error.error as any)?.code === 'two_factor_setup_required'
          && !router.url.startsWith('/account/two-factor')) {
        router.navigateByUrl('/account/two-factor');
      }
      if (error && (error.status === 401 || error.status === 419)) {
        const hasAuthUrl = (() => {
          const payload = error?.error as any;
          if (!payload || typeof payload !== 'object') return false;
          return !!(payload.authUrl || payload.auth_url);
        })();
        if (!hasAuthUrl) {
          try { store.clear(); } catch {}
        }
      }
      return throwError(() => error);
    })
  );
};
