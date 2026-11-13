import {Injectable, effect, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {AuthStore} from '../auth/auth.store';
import {Preferences} from './preferences';

export interface SchoolPrefs {
  naming_convention: 'first_last' | 'last_first';
  class_structure: 'grade_based' | 'form_based' | 'year_based';
  timezone: string;
  date_format: 'DD MMM YYYY' | 'MM/DD/YYYY' | 'DD/MM/YYYY';
  language: 'en' | 'fr' | 'ar';
  auto_save: boolean;
}

const CACHE_KEY = 'school_prefs';

/** The school's date format, as an Angular date pattern. */
const DATE_PATTERNS: Record<string, string> = {
  'DD MMM YYYY': 'd MMM y',
  'MM/DD/YYYY': 'MM/dd/y',
  'DD/MM/YYYY': 'dd/MM/y',
};

/** Date-only formats used across the templates: all become the school's date format. */
const DATE_ONLY = new Set([undefined, '', 'mediumDate', 'shortDate', 'longDate', 'd MMM y', 'MMM d, y']);
/** Date + time formats: the school's date format followed by the time. */
const DATE_TIME: Record<string, string> = {
  medium: ', h:mm a',
  short: ', h:mm a',
  'MMM d, y · HH:mm': ' · HH:mm',
};

let current: SchoolPrefs | null = null;
let patched = false;

/**
 * How the signed-in user's school wants things presented (School Settings):
 * time zone, date format, name order, class naming and default language.
 * Loaded with /auth/me and applied app-wide:
 *
 *  - Every `| date` in the app shows the school's time zone (correct across
 *    daylight-saving changes) and writes full dates in the school's format.
 *    This is done once, by wrapping Angular's DatePipe, rather than by
 *    touching each of the ~80 templates.
 *  - The school's language is the default for the header/menu wording until a
 *    user picks their own.
 *
 * Platform users and signed-out visitors get the defaults (browser time zone,
 * Angular's standard formats).
 */
@Injectable({providedIn: 'root'})
export class SchoolPrefsService {
  private readonly store = inject(AuthStore);
  private readonly preferences = inject(Preferences);

  readonly prefs = signal<SchoolPrefs | null>(this.readCache());

  constructor() {
    current = this.prefs();
    patchDatePipe();
    effect(() => {
      if (!this.store.isAuthenticated()) this.set(null);
    });
  }

  set(prefs: SchoolPrefs | null | undefined): void {
    const value = prefs ?? null;
    current = value;
    this.prefs.set(value);
    if (typeof window === 'undefined') return;
    try {
      if (value) localStorage.setItem(CACHE_KEY, JSON.stringify(value)); else localStorage.removeItem(CACHE_KEY);
    } catch {}
    if (value?.language) this.preferences.applySchoolDefault(value.language);
  }

  /** "First Last" or "Last, First", the way this school writes names. */
  name(first?: string | null, last?: string | null): string {
    const f = (first ?? '').trim(), l = (last ?? '').trim();
    return this.prefs()?.naming_convention === 'last_first' && f && l ? `${l}, ${f}` : `${f} ${l}`.trim();
  }

  /** Example class levels for the school's class structure (suggestions, not a restriction). */
  classLevels(): string[] {
    switch (this.prefs()?.class_structure) {
      case 'form_based': return ['JSS 1', 'JSS 2', 'JSS 3', 'SSS 1', 'SSS 2', 'SSS 3'];
      case 'year_based': return Array.from({length: 13}, (_, i) => `Year ${i + 1}`);
      default: return Array.from({length: 12}, (_, i) => `Grade ${i + 1}`);
    }
  }

  private readCache(): SchoolPrefs | null {
    if (typeof window === 'undefined') return null;
    try {
      const raw = localStorage.getItem(CACHE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }
}

/** "+0100" for an IANA zone at a given instant (so daylight-saving is right for that date). */
function offsetFor(zone: string, value: unknown): string | undefined {
  try {
    const date = value instanceof Date ? value : new Date(value as any);
    if (isNaN(date.getTime())) return undefined;
    const part = new Intl.DateTimeFormat('en-US', {timeZone: zone, timeZoneName: 'longOffset'})
      .formatToParts(date).find(p => p.type === 'timeZoneName')?.value ?? '';
    if (part === 'GMT') return '+0000';
    const m = /GMT([+-])(\d{1,2})(?::(\d{2}))?/.exec(part);
    return m ? `${m[1]}${m[2].padStart(2, '0')}${m[3] ?? '00'}` : undefined;
  } catch {
    return undefined;
  }
}

/** A date-only string ("2026-11-13") has no time of day, so it must not be shifted between zones. */
function isDateOnly(value: unknown): boolean {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

function patchDatePipe(): void {
  if (patched) return;
  patched = true;
  const original = DatePipe.prototype.transform as (...args: any[]) => any;
  (DatePipe.prototype as any).transform = function (value: any, format?: string, timezone?: string, locale?: string) {
    const prefs = current;
    if (!prefs || value === null || value === undefined || value === '') {
      return original.call(this, value, format, timezone, locale);
    }
    const pattern = DATE_PATTERNS[prefs.date_format];
    let fmt = format;
    if (pattern) {
      if (DATE_ONLY.has(format)) fmt = pattern;
      else if (format && DATE_TIME[format] !== undefined) fmt = pattern + DATE_TIME[format];
    }
    const tz = timezone ?? (isDateOnly(value) ? undefined : offsetFor(prefs.timezone, value));
    return original.call(this, value, fmt, tz, locale);
  };
}
