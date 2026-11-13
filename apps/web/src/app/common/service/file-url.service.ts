import {inject, Injectable} from '@angular/core';
import {API_BASE_URL} from './api.service';

/**
 * Turns a stored file reference into an address the browser can load directly.
 *
 * Requests made through ApiService are pointed at the API host by the HTTP
 * interceptor, but an <img>, <iframe>, <video> or link is loaded by the browser
 * itself. In production the app and the API are on different hosts, so a bare
 * "/backend/files?p=…" there would ask the app host for the file and get the
 * app page back instead. Anything bound to src or href goes through here.
 */
@Injectable({providedIn: 'root'})
export class FileUrlService {
  private readonly base = (inject(API_BASE_URL, {optional: true}) ?? '').replace(/\/+$/, '');

  resolve(ref: string | null | undefined): string {
    const v = (ref ?? '').trim();
    if (!v) return '';
    if (/^(https?:|blob:|data:)/i.test(v)) return v;          // already complete (API host, YouTube, a local preview)
    if (v.startsWith('/backend/')) return this.base + v;      // a served route
    return this.base + '/backend/files?p=' + v.replace(/^\/+/, ''); // a bare stored path
  }
}
