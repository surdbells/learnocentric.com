import {Injectable, effect, inject} from '@angular/core';
import {AuthStore} from '../auth/auth.store';

const CACHE_KEY = 'brand_color';

type Rgb = [number, number, number];

/**
 * Per-school theming. Given the school's brand colour (Settings → Branding),
 * re-derives the app's palette tokens (--brand-50…800 and the Bootstrap
 * primary/link values built on them) so buttons, links, active menu items
 * and highlights use that colour. Schools with no colour keep the default
 * green, as do the public pages and the sign-in screen.
 *
 * The colour is adjusted where needed so white text on buttons and brand
 * links on white both meet WCAG AA contrast (4.5:1); a very light or neon
 * brand colour is darkened rather than producing unreadable buttons.
 * Status colours (success/danger/warning badges and alerts) are untouched,
 * so "passed" and "overdue" keep their meaning under any brand.
 */
@Injectable({providedIn: 'root'})
export class BrandThemeService {
  private readonly store = inject(AuthStore);
  private applied: string | null = null;

  constructor() {
    // Signed out → back to the default theme (the next visitor may be from another school).
    effect(() => {
      if (!this.store.isAuthenticated()) this.apply(null);
    });
  }

  /** Re-apply the colour remembered from the last visit, to avoid a green flash on reload. */
  restore(): void {
    if (typeof window === 'undefined') return;
    try { this.paint(localStorage.getItem(CACHE_KEY)); } catch {}
  }

  /** Apply (or clear, with null) the signed-in school's brand colour. */
  apply(color: string | null | undefined): void {
    if (typeof window === 'undefined') return;
    const hex = normaliseHex(color);
    try {
      if (hex) localStorage.setItem(CACHE_KEY, hex); else localStorage.removeItem(CACHE_KEY);
    } catch {}
    this.paint(hex);
  }

  private paint(color: string | null | undefined): void {
    const hex = normaliseHex(color);
    if (hex === this.applied) return;
    this.applied = hex;
    const root = document.documentElement;
    const names = Object.keys(palette('#39c645'));
    if (!hex) {
      names.forEach(n => root.style.removeProperty(n));
      root.removeAttribute('data-brand');
      return;
    }
    for (const [name, value] of Object.entries(palette(hex))) root.style.setProperty(name, value);
    root.setAttribute('data-brand', hex);
  }
}

/** The CSS custom properties for a brand colour. */
export function palette(hex: string): Record<string, string> {
  const [h, s, l0] = rgbToHsl(hexToRgb(hex));
  // Tints keep the hue but calm the saturation so large light areas don't glare.
  const tint = (l: number, sat = 0.9) => hslToRgb([h, Math.min(s, 0.95) * sat, l]);

  // 500 is the brand itself, darkened until white text reads on it.
  let l500 = Math.min(l0, 0.52);
  while (l500 > 0.12 && contrast(hslToRgb([h, s, l500]), [255, 255, 255]) < 4.5) l500 -= 0.02;
  const c500 = hslToRgb([h, s, l500]);
  const c600 = hslToRgb([h, s, Math.max(0.1, l500 - 0.07)]);
  // 700/800 are used for links and text on white: keep them clearly readable.
  let l700 = Math.max(0.1, l500 - 0.13);
  while (l700 > 0.08 && contrast(hslToRgb([h, s, l700]), [255, 255, 255]) < 5.5) l700 -= 0.02;
  const c700 = hslToRgb([h, s, l700]);
  const c800 = hslToRgb([h, s, Math.max(0.07, l700 - 0.07)]);

  const c300 = tint(0.68), c400 = tint(0.58, 1);
  return {
    '--brand-50': toHex(tint(0.96)),
    '--brand-100': toHex(tint(0.91)),
    '--brand-200': toHex(tint(0.82)),
    '--brand-300': toHex(c300),
    '--brand-400': toHex(c400),
    '--brand-500': toHex(c500),
    '--brand-600': toHex(c600),
    '--brand-700': toHex(c700),
    '--brand-800': toHex(c800),
    '--brand-rgb': c500.join(', '),
    // Values styles.scss hard-codes as green literals.
    '--brand-link-rgb': c700.join(', '),
    '--brand-link-hover-rgb': c800.join(', '),
    '--brand-link-dark-rgb': c400.join(', '),
    '--brand-link-dark-hover-rgb': c300.join(', '),
    '--brand-dark-subtle': toHex(hslToRgb([h, Math.min(s, 0.6), 0.13])),
    '--brand-dark-border': toHex(hslToRgb([h, Math.min(s, 0.6), 0.24])),
  };
}

function normaliseHex(c: string | null | undefined): string | null {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(c ?? '').trim());
  if (!m) return null;
  const v = m[1].length === 3 ? m[1].split('').map(x => x + x).join('') : m[1];
  return '#' + v.toLowerCase();
}

function hexToRgb(hex: string): Rgb {
  const n = parseInt(normaliseHex(hex)!.slice(1), 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function toHex(rgb: Rgb): string {
  return '#' + rgb.map(v => Math.round(v).toString(16).padStart(2, '0')).join('');
}

function rgbToHsl([r, g, b]: Rgb): [number, number, number] {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
  if (max === min) return [0, 0, l];
  const d = max - min;
  const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
  const h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
  return [h / 6, s, l];
}

function hslToRgb([h, s, l]: [number, number, number]): Rgb {
  if (s === 0) { const v = Math.round(l * 255); return [v, v, v]; }
  const q = l < 0.5 ? l * (1 + s) : l + s - l * s, p = 2 * l - q;
  const f = (t: number) => {
    t = (t + 1) % 1;
    return t < 1 / 6 ? p + (q - p) * 6 * t : t < 1 / 2 ? q : t < 2 / 3 ? p + (q - p) * (2 / 3 - t) * 6 : p;
  };
  return [Math.round(f(h + 1 / 3) * 255), Math.round(f(h) * 255), Math.round(f(h - 1 / 3) * 255)];
}

function luminance([r, g, b]: Rgb): number {
  const c = [r, g, b].map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}

/** WCAG contrast ratio between two colours. */
export function contrast(a: Rgb, b: Rgb): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}
