import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';

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

  // Generic request that supports any HTTP method
  request<T = any, TBody = any>(method: string, url: string, options: ApiRequestOptions<TBody> = {}): Observable<T> {
    const { headers, params, body, ...rest } = options;
    return this.http.request<T>(method, url, {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      body,
      ...rest,
    });
  }

  get<T = any>(url: string, options: ApiRequestOptions = {}): Observable<T> {
    const { headers, params, ...rest } = options;
    return this.http.get<T>(url, {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      ...rest,
    });
  }

  post<T = any, TBody = any>(url: string, body?: TBody, options: ApiRequestOptions<TBody> = {}): Observable<T> {
    const { headers, params, ...rest } = options;
    return this.http.post<T>(url, body, {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      ...rest,
    });
  }

  put<T = any, TBody = any>(url: string, body?: TBody, options: ApiRequestOptions<TBody> = {}): Observable<T> {
    const { headers, params, ...rest } = options;
    return this.http.put<T>(url, body, {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      ...rest,
    });
  }

  patch<T = any, TBody = any>(url: string, body?: TBody, options: ApiRequestOptions<TBody> = {}): Observable<T> {
    const { headers, params, ...rest } = options;
    return this.http.patch<T>(url, body, {
      headers: toHttpHeaders(headers),
      params: toHttpParams(params),
      ...rest,
    });
  }

  // delete<T = any>(url: string, options: ApiRequestOptions = {}): Observable<T> {
  //   const { headers, params, body, ...rest } = options;
  //   return this.http.delete<T>(url, {
  //     headers: toHttpHeaders(headers),
  //     params: toHttpParams(params),
  //     body,
  //     ...rest,
  //   } as any);
  // }
}
