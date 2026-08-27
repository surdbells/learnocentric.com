/**
 * Shared types + helpers for the Phase-0 UI kit, the reusable building blocks
 * (KPI cards, right-rail panels, tabs, rings, table cells) that the design
 * mockups repeat on nearly every screen. Theme stays green: tones map to
 * Bootstrap contextual colours, tinted via CSS custom properties.
 */

export type Tone = 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'secondary';

/** Bootstrap colour + rgb var pair for a tone, for `[style.--ui-tone]` bindings. */
export function toneVars(tone: Tone | undefined | null): { color: string; rgb: string } {
  switch (tone) {
    case 'success':   return { color: 'var(--bs-success)',   rgb: 'var(--bs-success-rgb)' };
    case 'warning':   return { color: 'var(--bs-warning)',   rgb: 'var(--bs-warning-rgb)' };
    case 'danger':    return { color: 'var(--bs-danger)',    rgb: 'var(--bs-danger-rgb)' };
    case 'info':      return { color: 'var(--bs-info)',      rgb: 'var(--bs-info-rgb)' };
    case 'secondary': return { color: 'var(--bs-secondary)', rgb: 'var(--bs-secondary-rgb)' };
    case 'primary':
    default:          return { color: 'var(--brand-600)',    rgb: 'var(--brand-rgb)' };
  }
}

export interface KpiItem {
  label: string;
  value: string | number;
  /** Signed delta, e.g. "+4.3%"; sets colour by direction unless deltaTone given. */
  delta?: string;
  deltaDir?: 'up' | 'down' | 'flat';
  deltaLabel?: string;      // e.g. "vs last term"
  icon?: string;
  tone?: Tone;              // icon chip tone
  sublabel?: string;
  spark?: number[];         // optional mini trend
  link?: string;            // routerLink for the whole card
}

export interface AttentionItem {
  label: string;
  sublabel?: string;
  count?: number | string;
  tone?: Tone;
  icon?: string;
  link?: string;
  time?: string;            // e.g. "2h ago"
}

export interface QuickAction {
  label: string;
  sublabel?: string;
  icon: string;
  tone?: Tone;
  link?: string;            // routerLink; omit to emit (action) instead
  key?: string;             // identifier emitted on click when no link
}

export interface TabItem {
  key: string;
  label: string;
  count?: number | string;
}
