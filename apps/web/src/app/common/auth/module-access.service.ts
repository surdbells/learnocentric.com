import {computed, inject, Injectable, signal} from '@angular/core';
import {ApiService} from '../service/api.service';
import {SchoolPrefsService} from '../service/school-prefs.service';

const MODULES_KEY = 'granted_modules';
const MATRIX_KEY = 'role_matrix';

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
  private readonly schoolPrefs = inject(SchoolPrefsService);

  /** Where the organisation's subscription stands (from /auth/me), or null when it has none. */
  readonly subscription = signal<any | null>(null);

  /** null = not yet loaded on this client (fail-open in the UI; backend still enforces). */
  private readonly _modules = signal<string[] | null>(this.readCache());
  readonly modules = computed(() => this._modules());

  /** True when the module is granted, or when we haven't loaded the list yet. */
  has(module: string | undefined | null): boolean {
    if (!module) return true;
    const list = this._modules();
    return list === null ? true : list.includes(module);
  }

  /**
   * Custom-role permission matrix (module → granted actions), or null for
   * system roles, which aren't narrowed. Mirrors RequirePermissionMiddleware.
   */
  private readonly _matrix = signal<Record<string, string[]> | null>(this.readMatrix());

  /** True unless the user's custom role lacks "view" on every listed RBAC module. */
  canView(perm: string | string[] | undefined | null): boolean {
    if (!perm) return true;
    const m = this._matrix();
    if (m === null) return true;
    const list = Array.isArray(perm) ? perm : [perm];
    return list.some(p => (m[p] ?? []).includes('view'));
  }

  /**
   * Keep only the entries (hub tabs, tiles…) the school's plan includes and the
   * user's role may view. Reactive: re-evaluates when modules/matrix load.
   */
  visible<T extends {module?: string; perm?: string | string[]}>(items: T[]): T[] {
    return items.filter(i => this.has(i.module) && this.canView(i.perm));
  }

  /** Fetch the granted modules (and custom-role matrix) for the current session and cache them. */
  refresh(): void {
    this.api.get<{ modules?: string[]; role_matrix?: Record<string, string[]> | null }>('/backend/auth/me').subscribe({
      next: (res: any) => {
        this.set(res?.modules ?? []);
        this.setMatrix(res?.role_matrix ?? null);
        // The school's presentation preferences (time zone, date format, names, language).
        this.schoolPrefs.set(res?.school ?? null);
        this.subscription.set(res?.subscription ?? null);
      },
      error: () => { /* leave cache as-is; backend still enforces */ },
    });
  }

  private setMatrix(m: Record<string, string[]> | null): void {
    this._matrix.set(m);
    if (typeof window !== 'undefined') {
      try {
        if (m === null) localStorage.removeItem(MATRIX_KEY); else localStorage.setItem(MATRIX_KEY, JSON.stringify(m));
      } catch {}
    }
  }

  private readMatrix(): Record<string, string[]> | null {
    if (typeof window === 'undefined') return null;
    try {
      const raw = localStorage.getItem(MATRIX_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  set(modules: string[]): void {
    this._modules.set(modules);
    if (typeof window !== 'undefined') {
      try { localStorage.setItem(MODULES_KEY, JSON.stringify(modules)); } catch {}
    }
  }

  clear(): void {
    this._modules.set(null);
    this._matrix.set(null);
    if (typeof window !== 'undefined') {
      try { localStorage.removeItem(MODULES_KEY); localStorage.removeItem(MATRIX_KEY); } catch {}
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
