import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../common/ui';

/**
 * Academic calendar (school admin) — an agenda of the institution's dated events
 * (scheduled live classes + worksheet deadlines) from /backend/school/calendar,
 * with KPI cards and type tabs. Agenda view grouped by date; month-grid is a
 * later enhancement.
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

  /** Filtered events grouped by date for the agenda list. */
  readonly grouped = computed<{ date: string; items: any[] }[]>(() => {
    const map = new Map<string, any[]>();
    for (const ev of this.filtered()) {
      if (!map.has(ev.date)) map.set(ev.date, []);
      map.get(ev.date)!.push(ev);
    }
    return [...map.entries()].map(([date, items]) => ({date, items}));
  });

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/school/calendar').subscribe({
      next: (res) => { this.events.set(res?.events ?? []); if (res?.stats) this.stats.set(res.stats); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the calendar'); },
    });
  }

  typeIcon(t: string): string { return t === 'live_class' ? 'video_camera_front' : 'assignment'; }
  typeColor(t: string): string { return t === 'live_class' ? 'danger' : 'warning'; }
  typeLabel(t: string): string { return t === 'live_class' ? 'Live class' : 'Worksheet due'; }
}
