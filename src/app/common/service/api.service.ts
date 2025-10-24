import { Injectable, InjectionToken, inject } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { Observable, EMPTY } from 'rxjs';

// Add: a simple token to provide base URL at runtime (configure in app bootstrap)
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL');

export type Primitive = string | number | boolean | null | undefined;
export type ParamValue = Primitive | ReadonlyArray<Primitive>;

export type ParamsInput =
  | HttpParams
  | Record<string, ParamValue>;

export type HeadersInput =
  | HttpHeaders
  | Record<string, string | number | undefined | null>;

export interface ApiRequestOptions<TBody = any> {
  headers?: HeadersInput;
  params?: ParamsInput;
  body?: TBody;
  // If true or string (message), ApiService will show a confirmation before DELETE (browser only)
  // - true (default): use default message
  // - string: use as the confirmation message
  // - false: skip confirmation
  confirm?: boolean | string;
  // Alternative way to customize the confirmation message
  confirmMessage?: string;
  // For advanced cases: you can pass through any other HttpClient options via spread
  // e.g. reportProgress, withCredentials, observe, responseType, context, etc.
  [key: string]: any;
}

function toHttpParams(params?: ParamsInput): HttpParams | undefined {
  if (!params) return undefined;
  if (params instanceof HttpParams) return params;

  let hp = new HttpParams();
  for (const key of Object.keys(params)) {
    const value = params[key];
    if (value === undefined || value === null) continue;

    if (Array.isArray(value)) {
      value.forEach(v => {
        if (v === undefined || v === null) return;
        hp = hp.append(key, String(v));
      });
    } else {
      hp = hp.set(key, String(value));
    }
  }
  return hp;
}

// ... existing code ...
function toHttpHeaders(headers?: HeadersInput): HttpHeaders | undefined {
  if (!headers) return undefined;
  if (headers instanceof HttpHeaders) return headers;

  let hh = new HttpHeaders();
  for (const key of Object.keys(headers)) {
    const value = headers[key];
    if (value === undefined || value === null) continue;
    hh = hh.set(key, String(value));
  }
  return hh;
}

@Injectable({ providedIn: 'root' })
export class ApiService {
  private http = inject(HttpClient);
  // Add: inject base URL (optional). If not provided, defaults to empty string.
  private baseUrl = inject(API_BASE_URL, { optional: true }) ?? '';

  private resolveUrl(path: string): string {
    // If path is absolute (http/https), return as-is
    if (/^https?:\/\//i.test(path)) return path;
    // Join baseUrl and path safely
    const a = this.baseUrl?.replace(/\/+$/, '') ?? '';
    const b = path.replace(/^\/+/, '');
    return a ? `${a}/${b}` : `/${b}`;
  }

  // Generic request that supports any HTTP method
  request<T = any, TBody = any>(method: string, url: string, options: ApiRequestOptions<TBody> = {}): Observable<T> {
    const { headers, params, body, ...rest } = options;
    return this.http.request<T>(method, this.resolveUrl(url), {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      body,
      ...rest,
    });
  }

  get<T = any>(url: string, options: ApiRequestOptions = {}): Observable<T> {
    const { headers, params, ...rest } = options;
    return this.http.get<T>(this.resolveUrl(url), {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      ...rest,
    });
  }

  post<T = any, TBody = any>(url: string, body?: TBody, options: ApiRequestOptions<TBody> = {}): Observable<T> {
    const { headers, params, ...rest } = options;
    return this.http.post<T>(this.resolveUrl(url), body, {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      ...rest,
    });
  }

  put<T = any, TBody = any>(url: string, body?: TBody, options: ApiRequestOptions<TBody> = {}): Observable<T> {
    const { headers, params, ...rest } = options;
    return this.http.put<T>(this.resolveUrl(url), body, {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      ...rest,
    });
  }

  patch<T = any, TBody = any>(url: string, body?: TBody, options: ApiRequestOptions<TBody> = {}): Observable<T> {
    const { headers, params, ...rest } = options;
    return this.http.patch<T>(this.resolveUrl(url), body, {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      ...rest,
    });
  }

  delete<T = any>(url: string, options: ApiRequestOptions = {}): Observable<T> {
    const { headers, params, body, confirm, confirmMessage, ...rest } = options as ApiRequestOptions & { confirm?: boolean | string; confirmMessage?: string };

    // Default: confirm deletions in browser environments, unless explicitly disabled
    const isBrowser = typeof window !== 'undefined' && typeof (window as any).confirm === 'function';
    const shouldConfirm = (confirm !== false);

    if (isBrowser && shouldConfirm) {
      const message = typeof confirm === 'string'
        ? confirm
        : (confirmMessage ?? 'Are you sure you want to delete this item? This action cannot be undone.');
      const ok = (window as any).confirm(message);
      if (!ok) {
        // User cancelled: return EMPTY to complete without making the HTTP call
        return EMPTY as Observable<T>;
      }
    }

    return this.http.delete<T>(this.resolveUrl(url), {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      body,
      ...rest,
    });
  }
}
