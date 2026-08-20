import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../common/ui';

interface CalDay {iso: string; day: number; weekday: string; inMonth: boolean; isToday: boolean; items: any[]}

/**
 * Academic calendar (school admin) — the institution's dated events (scheduled
 * live classes + worksheet deadlines) from /backend/school/calendar. Offers a
 * month grid, a week view and a grouped agenda, all sharing the type filter.
 */
@Component({
  selector: 'app-calendar',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, KpiStrip, TabBar],
  templateUrl: './calendar.html',
  styleUrl: './calendar.css',
})
export class Calendar {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  events = signal<any[]>([]);
  stats = signal<any>({total: 0, live_classes: 0, worksheet_due: 0, this_month: 0});
  typeTab = signal<string>('all');

  /** month = grid, week = 7-day columns, agenda = grouped list. */
  view = signal<'month' | 'week' | 'agenda'>('month');
  /** The date the month / week view is focused on. */
  cursor = signal<Date>(new Date());
  private readonly today = new Date();

  readonly weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.stats();
    return [
      {label: 'Total events', value: s.total ?? 0, icon: 'calendar_month', tone: 'primary'},
      {label: 'Live classes', value: s.live_classes ?? 0, icon: 'video_camera_front', tone: 'danger'},
      {label: 'Worksheet deadlines', value: s.worksheet_due ?? 0, icon: 'assignment', tone: 'warning'},
      {label: 'This month', value: s.this_month ?? 0, icon: 'schedule', tone: 'info'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const e = this.events();
    return [
      {key: 'all', label: 'All', count: e.length},
      {key: 'live_class', label: 'Live Classes', count: e.filter(x => x.type === 'live_class').length},
      {key: 'worksheet_due', label: 'Deadlines', count: e.filter(x => x.type === 'worksheet_due').length},
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.typeTab(), e = this.events();
    return t === 'all' ? e : e.filter(x => x.type === t);
  });

  /** Filtered events indexed by their YYYY-MM-DD date. */
  private readonly byDate = computed<Map<string, any[]>>(() => {
    const map = new Map<string, any[]>();
    for (const ev of this.filtered()) {
      if (!map.has(ev.date)) map.set(ev.date, []);
      map.get(ev.date)!.push(ev);
    }
    // keep each day's events in chronological order
    for (const list of map.values()) list.sort((a, b) => (a.datetime ?? '').localeCompare(b.datetime ?? ''));
    return map;
  });

  /** Filtered events grouped by date for the agenda list. */
  readonly grouped = computed<{date: string; items: any[]}[]>(() =>
    [...this.byDate().entries()].sort((a, b) => a[0].localeCompare(b[0])).map(([date, items]) => ({date, items})));

  /** The month grid — whole weeks (Sun–Sat) covering the cursor's month. */
  readonly monthCells = computed<CalDay[]>(() => {
    const c = this.cursor(), y = c.getFullYear(), m = c.getMonth();
    const offset = new Date(y, m, 1).getDay();
    const daysInMonth = new Date(y, m + 1, 0).getDate();
    const total = Math.ceil((offset + daysInMonth) / 7) * 7;
    const map = this.byDate();
    const cells: CalDay[] = [];
    for (let i = 0; i < total; i++) {
      const d = new Date(y, m, 1 - offset + i);
      const iso = this.iso(d);
      cells.push({iso, day: d.getDate(), weekday: this.weekdays[d.getDay()], inMonth: d.getMonth() === m, isToday: iso === this.iso(this.today), items: map.get(iso) ?? []});
    }
    return cells;
  });

  /** The 7 days of the week containing the cursor. */
  readonly weekCells = computed<CalDay[]>(() => {
    const c = this.cursor();
    const start = new Date(c.getFullYear(), c.getMonth(), c.getDate() - c.getDay());
    const map = this.byDate();
    return Array.from({length: 7}, (_, i) => {
      const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
      const iso = this.iso(d);
      return {iso, day: d.getDate(), weekday: this.weekdays[d.getDay()], inMonth: true, isToday: iso === this.iso(this.today), items: map.get(iso) ?? []};
    });
  });

  readonly periodLabel = computed<string>(() => {
    const c = this.cursor();
    if (this.view() === 'week') {
      const s = new Date(c.getFullYear(), c.getMonth(), c.getDate() - c.getDay());
      const e = new Date(s.getFullYear(), s.getMonth(), s.getDate() + 6);
      const sameYear = s.getFullYear() === e.getFullYear();
      return `${s.toLocaleDateString(undefined, {month: 'short', day: 'numeric'})} – ${e.toLocaleDateString(undefined, {month: 'short', day: 'numeric', ...(sameYear ? {} : {year: 'numeric'})})}, ${e.getFullYear()}`;
    }
    return c.toLocaleDateString(undefined, {month: 'long', year: 'numeric'});
  });

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/school/calendar').subscribe({
      next: (res) => { this.events.set(res?.events ?? []); if (res?.stats) this.stats.set(res.stats); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the calendar'); },
    });
  }

  setView(v: 'month' | 'week' | 'agenda'): void { this.view.set(v); }

  prev(): void { this.shift(-1); }
  next(): void { this.shift(1); }
  goToday(): void { this.cursor.set(new Date()); }

  private shift(dir: number): void {
    const c = new Date(this.cursor());
    if (this.view() === 'week') c.setDate(c.getDate() + 7 * dir);
    else c.setMonth(c.getMonth() + dir);
    this.cursor.set(c);
  }

  private iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  typeIcon(t: string): string { return t === 'live_class' ? 'video_camera_front' : 'assignment'; }
  typeColor(t: string): string { return t === 'live_class' ? 'danger' : 'warning'; }
  typeLabel(t: string): string { return t === 'live_class' ? 'Live class' : 'Worksheet due'; }
}
