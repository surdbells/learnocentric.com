import { Injectable, signal, computed } from '@angular/core';
import { AuthSession, AuthUser } from './auth.models';

const TOKEN_KEY = 'auth_token';
const USER_KEY = 'auth_user';
const MODULES_KEY = 'granted_modules';

@Injectable({ providedIn: 'root' })
export class AuthStore {
  private readonly _session = signal<AuthSession>({ token: null, user: null, isAuthenticated: false });

  readonly session = computed(() => this._session());
  readonly token = computed(() => this._session().token);
  readonly user = computed(() => this._session().user);
  readonly isAuthenticated = computed(() => this._session().isAuthenticated);

  setSession(token: string | null, user: AuthUser | null) {
    this._session.set({ token, user, isAuthenticated: !!(token || this.hasHttpOnlySessionCookie()) });
  }

  clear() {
    this._session.set({ token: null, user: null, isAuthenticated: false });
    if (typeof window !== 'undefined') {
      try {
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
        localStorage.removeItem(MODULES_KEY);
      } catch {}
    }
  }

  loadFromStorage() {
    if (typeof window === 'undefined') return;
    try {
      const token = localStorage.getItem(TOKEN_KEY);
      const rawUser = localStorage.getItem(USER_KEY);
      const user = rawUser ? (JSON.parse(rawUser) as AuthUser) : null;
      // If using HttpOnly cookie-based auth, token may be null but session is authenticated due to cookie presence
      this.setSession(token, user);
    } catch {
      this.setSession(null, null);
    }
  }

  persist(token: string | null, user: AuthUser | null) {
    if (typeof window === 'undefined') return;
    try {
      if (token) localStorage.setItem(TOKEN_KEY, token); else localStorage.removeItem(TOKEN_KEY);
      if (user) localStorage.setItem(USER_KEY, JSON.stringify(user)); else localStorage.removeItem(USER_KEY);
    } catch {}
    this.setSession(token, user);
  }

  private hasHttpOnlySessionCookie(): boolean {
    // Heuristic: we cannot detect HttpOnly cookies directly. Optionally base on presence of a non-sensitive marker.
    // Returning false keeps logic simple; withCredentials ensures cookies are sent regardless.
    return false;
  }
}
