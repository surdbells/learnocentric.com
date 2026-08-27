import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {
  KpiItem, KpiStrip, LineChart, DonutChart, DonutSegment, StackedBar, BarSeries, BarList, BarItem, HeatGrid, HeatRow,
} from '../../../../common/ui';

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super admins', school_admin: 'School admins', tutor_admin: 'Tutor admins',
  academic_lead: 'Academic leads', teacher: 'Teachers', student: 'Learners', parent: 'Parents',
};

@Component({
  selector: 'app-super-admin-analytics',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, KpiStrip, LineChart, DonutChart, StackedBar, BarList, HeatGrid],
  templateUrl: './analytics.html',
  styleUrl: './analytics.css',
})
export class SuperAdminAnalytics {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  data = signal<any | null>(null);

  readonly totals = computed<any>(() => this.data()?.totals ?? null);

  /** Signed delta helper → {delta, dir}. */
  private delta(key: string): {delta?: string; deltaDir?: 'up' | 'down' | 'flat'; deltaLabel?: string} {
    const v = this.data()?.deltas?.[key];
    if (v === undefined || v === null) return {};
    const dir = v > 0 ? 'up' : v < 0 ? 'down' : 'flat';
    return {delta: (v > 0 ? '+' : '') + v + '%', deltaDir: dir, deltaLabel: 'vs prev 30d'};
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const t = this.totals();
    if (!t) return [];
    const g = this.data()?.growth ?? [];
    const usersTrend = g.map((m: any) => m.users);
    const attemptsTrend = g.map((m: any) => m.attempts);
    const atRisk = (this.data()?.institutions ?? []).filter((i: any) => i.attempts === 0).length;
    return [
      {label: 'Institutions', value: t.institutions, icon: 'apartment', tone: 'primary', ...this.delta('institutions')},
      {label: 'Platform users', value: t.users, icon: 'group', tone: 'info', spark: usersTrend, ...this.delta('users')},
      {label: 'Learners', value: t.students, icon: 'school', tone: 'success'},
      {label: 'Graded attempts', value: t.graded_attempts, icon: 'quiz', tone: 'warning', spark: attemptsTrend, ...this.delta('attempts')},
      {label: 'Avg score', value: t.avg_score === null ? '-' : t.avg_score + '%', icon: 'workspace_premium', tone: 'success'},
      {label: 'At-risk schools', value: atRisk, sublabel: 'no activity', icon: 'warning', tone: atRisk > 0 ? 'danger' : 'secondary'},
    ];
  });

  // DAU line
  readonly dauSeries = computed<number[]>(() => (this.data()?.activity ?? []).map((d: any) => d.active));
  readonly dauLabels = computed<string[]>(() => {
    const a = this.data()?.activity ?? [];
    if (!a.length) return [];
    const pick = (i: number) => new Date(a[i].day).toLocaleString('en', {month: 'short', day: 'numeric'});
    return [pick(0), pick(Math.floor(a.length / 2)), pick(a.length - 1)];
  });
  readonly peakDau = computed<number>(() => Math.max(0, ...this.dauSeries()));

  // Top institutions + attention
  readonly topInstitutions = computed<BarItem[]>(() =>
    (this.data()?.institutions ?? []).filter((i: any) => i.attempts > 0).slice(0, 6).map((i: any) => ({label: i.name, value: i.attempts})));
  readonly attention = computed<any[]>(() =>
    (this.data()?.institutions ?? []).filter((i: any) => i.attempts === 0)
      .map((i: any) => ({name: i.name, reason: i.students > 0 ? 'No assessment activity' : 'No learners enrolled'})));

  // Usage by role donut
  readonly roleDonut = computed<DonutSegment[]>(() =>
    (this.data()?.roles ?? []).map((r: any) => ({label: ROLE_LABELS[r.role] ?? r.role, value: r.count})));
  readonly totalUsers = computed<number>(() => this.totals()?.users ?? 0);

  // Completion trend stacked
  readonly completionSeries = computed<BarSeries[]>(() => {
    const t = this.data()?.completion_trend ?? [];
    return [
      {label: 'Completed', tone: 'success', values: t.map((m: any) => m.completed)},
      {label: 'In progress', tone: 'warning', values: t.map((m: any) => m.in_progress)},
    ];
  });
  readonly completionLabels = computed<string[]>(() => (this.data()?.completion_trend ?? []).map((m: any) => this.monthLabel(m.month)));

  // Content by subject
  readonly contentBars = computed<BarItem[]>(() =>
    (this.data()?.content_by_subject ?? []).map((c: any) => ({label: c.subject, value: c.count, tone: 'info'})));

  // Heatmap
  readonly heatRows = computed<HeatRow[]>(() =>
    (this.data()?.heatmap ?? []).map((w: any) => ({label: w.label, values: w.values})));
  readonly heatCols = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  readonly recentActivity = computed<any[]>(() => this.data()?.recent_activity ?? []);
  readonly plans = computed<any[]>(() => this.data()?.plans ?? []);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/platform/analytics').subscribe({
      next: (res) => { this.data.set(res); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load platform analytics'); },
    });
  }

  monthLabel(ym: string): string {
    const [y, m] = (ym ?? '').split('-').map(Number);
    if (!y || !m) return ym;
    return new Date(y, m - 1, 1).toLocaleString('en', {month: 'short'});
  }
  naira(v: number | null | undefined): string {
    if (v === null || v === undefined) return '₦0';
    return '₦' + Number(v).toLocaleString('en-NG');
  }
}
