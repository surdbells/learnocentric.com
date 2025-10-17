import { Injectable, inject } from '@angular/core';
import {AuthResponse, AuthSession, AuthUser} from './auth.models';
import { AuthStore } from './auth.store';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private store = inject(AuthStore);

  initFromStorage() {
    this.store.loadFromStorage();
  }

  // Call this after a successful login API that returns token/user in the body
  // If backend sets HttpOnly cookies and returns only user, pass token as null.
  persistLogin(resp: AuthResponse) {
    const token = resp.token || resp.accessToken || resp.data?.token || null;
    const user: AuthUser | null = resp.user || resp.data?.user || null;
    this.store.persist(token ?? null, user);
  }

  // Call this if the backend sets HttpOnly cookie and returns user separately
  setUserFromBackend(user: AuthUser | null) {
    this.store.persist(null, user);
  }

  logoutLocal() {
    this.store.clear();
  }

  getAuthSession(): AuthSession {
    return this.store.session();
  }
}
