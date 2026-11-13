import { inject } from '@angular/core';
import { HttpErrorResponse, HttpInterceptorFn, HttpResponse } from '@angular/common/http';
import { AuthStore } from './auth.store';
import { API_BASE_URL } from '../service/api.service';
import { catchError, tap, throwError } from 'rxjs';
import { Router } from '@angular/router';
import { ToastrService } from 'ngx-toastr';

/** Pages a signed-out visitor may be on; never bounced to /login from these. */
const PUBLIC_PATHS = ['/login', '/register', '/forgot-password', '/reset-password', '/verify-email'];
let lastNoticeAt = 0;

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
  const toast = inject(ToastrService, { optional: true });

  /** One toast per burst of failing requests, then back to sign-in. */
  const endSession = (message: string): void => {
    try { store.clear(); } catch {}
    const onPublicPage = router.url === '/' || PUBLIC_PATHS.some(p => router.url.startsWith(p));
    if (onPublicPage) return;
    if (Date.now() - lastNoticeAt > 4000) {
      lastNoticeAt = Date.now();
      toast?.info(message);
    }
    router.navigateByUrl('/login');
  };

  return next(authReq).pipe(
    tap((event) => {
      // Sliding session: the API hands back a fresh token while we stay active.
      if (event instanceof HttpResponse) {
        const fresh = event.headers.get('X-Session-Token');
        if (fresh && store.token()) store.persist(fresh, store.user());
      }
    }),
    catchError((error: HttpErrorResponse) => {
      const code = (error?.error as any)?.code;
      // The platform requires 2FA for staff and this account hasn't enrolled yet.
      if (error?.status === 403 && code === 'two_factor_setup_required'
          && !router.url.startsWith('/account/two-factor')) {
        router.navigateByUrl('/account/two-factor');
      }
      // Maintenance mode, or the parent portal was switched off, while signed in.
      if (token && (code === 'maintenance' || code === 'portal_disabled')) {
        endSession((error.error as any)?.error ?? 'The platform is unavailable right now.');
      }
      if (error && (error.status === 401 || error.status === 419)) {
        const hasAuthUrl = (() => {
          const payload = error?.error as any;
          if (!payload || typeof payload !== 'object') return false;
          return !!(payload.authUrl || payload.auth_url);
        })();
        if (!hasAuthUrl) {
          if (token) {
            // A session that existed has ended (inactivity timeout or sign-out elsewhere).
            endSession('You were signed out after a period of inactivity. Please sign in again.');
          } else {
            try { store.clear(); } catch {}
          }
        }
      }
      return throwError(() => error);
    })
  );
};
