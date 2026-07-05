import {computed, inject, Injectable, signal} from '@angular/core';
import {ApiService} from '../service/api.service';

const MODULES_KEY = 'granted_modules';

/**
 * Tracks which gateable feature modules the signed-in user's institution may use
 * (mirrors the backend SubscriptionPlan::MODULES / ModuleGateMiddleware).
 *
 * The list is fetched from /backend/auth/me at login and cached in localStorage
 * so menu filtering and route guards can read it synchronously (SSR-safe). The
 * backend remains the source of truth and enforces the same gate on every route.
 */
@Injectable({providedIn: 'root'})
export class ModuleAccessService {
  private readonly api = inject(ApiService);

  /** null = not yet loaded on this client (fail-open in the UI; backend still enforces). */
  private readonly _modules = signal<string[] | null>(this.readCache());
  readonly modules = computed(() => this._modules());

  /** True when the module is granted, or when we haven't loaded the list yet. */
  has(module: string | undefined | null): boolean {
    if (!module) return true;
    const list = this._modules();
    return list === null ? true : list.includes(module);
  }

  /** Fetch the granted modules for the current session and cache them. */
  refresh(): void {
    this.api.get<{ modules?: string[] }>('/backend/auth/me').subscribe({
      next: (res) => this.set(res?.modules ?? []),
      error: () => { /* leave cache as-is; backend still enforces */ },
    });
  }

  set(modules: string[]): void {
    this._modules.set(modules);
    if (typeof window !== 'undefined') {
      try { localStorage.setItem(MODULES_KEY, JSON.stringify(modules)); } catch {}
    }
  }

  clear(): void {
    this._modules.set(null);
    if (typeof window !== 'undefined') {
      try { localStorage.removeItem(MODULES_KEY); } catch {}
    }
  }

  private readCache(): string[] | null {
    if (typeof window === 'undefined') return null;
    try {
      const raw = localStorage.getItem(MODULES_KEY);
      return raw ? (JSON.parse(raw) as string[]) : null;
    } catch {
      return null;
    }
  }
}
