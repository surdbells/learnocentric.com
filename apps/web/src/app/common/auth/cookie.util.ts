// Lightweight cookie helper. Note: HttpOnly cookies cannot be read/modified from JS.
// We still set Secure and SameSite for best-effort security for non-HttpOnly cookies.
export class CookieUtil {
  static set(name: string, value: string, days = 7, options?: { path?: string; sameSite?: 'Strict' | 'Lax' | 'None'; secure?: boolean; domain?: string }) {
    if (typeof document === 'undefined') return; // SSR guard
    const date = new Date();
    date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
    let cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; Expires=${date.toUTCString()};`;
    cookie += ` Path=${options?.path ?? '/'};`;
    const sameSite = options?.sameSite ?? 'Strict';
    cookie += ` SameSite=${sameSite};`;
    const secure = options?.secure ?? (sameSite !== 'None' ? true : false);
    if (secure) cookie += ' Secure;';
    if (options?.domain) cookie += ` Domain=${options.domain};`;
    document.cookie = cookie;
  }

  static get(name: string): string | null {
    if (typeof document === 'undefined') return null; // SSR guard
    const decoded = decodeURIComponent(document.cookie || '');
    const parts = decoded.split('; ').map(p => p.trim());
    for (const part of parts) {
      if (!part) continue;
      const [k, ...rest] = part.split('=');
      if (k === encodeURIComponent(name) || k === name) {
        return rest.join('=');
      }
    }
    return null;
  }

  static remove(name: string, options?: { path?: string; domain?: string }) {
    if (typeof document === 'undefined') return; // SSR guard
    document.cookie = `${encodeURIComponent(name)}=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=${options?.path ?? '/'};${options?.domain ? ` Domain=${options.domain};` : ''}`;
  }
}
