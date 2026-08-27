import {Injectable, Inject, PLATFORM_ID, computed, inject, signal} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {ApiService} from './api.service';
import {AuthService} from '../auth/auth.service';

export interface TermOption {
  id: number;
  name: string;
  session_id?: number;
  status?: string;
  is_current?: boolean;
}

const ACTIVE_TERM_KEY = 'activeTermId';

/**
 * Global academic Session/Term context. Backs the top-bar term switcher and is
 * the single source of truth screens read to scope their data by term. Loads
 * the institution's terms from `/backend/school/terms`; the active term persists
 * in localStorage and defaults to the one flagged `is_current`.
 */
@Injectable({providedIn: 'root'})
export class TermContext {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);

  readonly terms = signal<TermOption[]>([]);
  readonly activeId = signal<number | null>(null);
  readonly active = computed<TermOption | null>(
    () => this.terms().find(t => t.id === this.activeId()) ?? null,
  );

  private loaded = false;

  constructor(@Inject(PLATFORM_ID) private platformId: Object) {}

  /** Fetch terms once; safe to call from every switcher instance. */
  load(): void {
    if (this.loaded || !isPlatformBrowser(this.platformId)) return;
    // Terms are institution-scoped; platform users (e.g. super admin, no institution)
    // have none, skip the fetch so the switcher stays hidden for them.
    if (!this.auth.getAuthSession()?.user?.institutionId) return;
    this.loaded = true;
    this.api.get<TermOption[]>('/backend/school/terms').subscribe({
      next: (rows) => {
        const terms = rows ?? [];
        this.terms.set(terms);
        const stored = Number(localStorage.getItem(ACTIVE_TERM_KEY));
        const pick =
          terms.find(t => t.id === stored) ??
          terms.find(t => t.is_current) ??
          terms[0];
        this.activeId.set(pick?.id ?? null);
      },
      error: () => { this.loaded = false; }, // allow a later retry
    });
  }

  setActive(id: number): void {
    this.activeId.set(id);
    if (isPlatformBrowser(this.platformId)) {
      localStorage.setItem(ACTIVE_TERM_KEY, String(id));
    }
  }
}
