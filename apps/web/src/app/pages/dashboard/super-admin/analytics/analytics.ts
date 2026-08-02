import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {KpiItem, KpiStrip, SparkBar, StatusBadge} from '../../../../common/ui';

/** Human labels for the role codes returned by the aggregation API. */
const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super admins', school_admin: 'School admins', tutor_admin: 'Tutor admins',
  academic_lead: 'Academic leads', teacher: 'Teachers', student: 'Students', parent: 'Parents',
};

@Component({
  selector: 'app-super-admin-analytics',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, KpiStrip, SparkBar, StatusBadge],
  templateUrl: './analytics.html',
  styleUrl: './analytics.css',
})
export class SuperAdminAnalytics {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  data = signal<any | null>(null);

  readonly kpis = computed<KpiItem[]>(() => {
    const t = this.data()?.totals;
    if (!t) return [];
    const g = this.data()?.growth ?? [];
    const usersTrend = g.map((m: any) => m.users);
    const attemptsTrend = g.map((m: any) => m.attempts);
    return [
      {label: 'Institutions', value: t.institutions, sublabel: `${t.active_institutions} active`, icon: 'apartment', tone: 'primary'},
      {label: 'Platform users', value: t.users, icon: 'group', tone: 'info', spark: usersTrend},
      {label: 'Learners', value: t.students, icon: 'school', tone: 'success'},
      {label: 'Graded attempts', value: t.graded_attempts, sublabel: t.avg_score === null ? 'no scores yet' : `${t.avg_score}% avg`, icon: 'quiz', tone: 'warning', spark: attemptsTrend},
    ];
  });

  /** Monthly growth series, tagged with a short month label for the axis. */
  readonly growth = computed<any[]>(() => (this.data()?.growth ?? []).map((m: any) => ({...m, label: this.monthLabel(m.month)})));
  readonly usersSeries = computed<number[]>(() => this.growth().map(m => m.users));
  readonly attemptsSeries = computed<number[]>(() => this.growth().map(m => m.attempts));
  readonly institutionsSeries = computed<number[]>(() => this.growth().map(m => m.institutions));

  readonly activity = computed<any[]>(() => this.data()?.activity ?? []);
  readonly activitySeries = computed<number[]>(() => this.activity().map((d: any) => d.active));
  readonly peakDau = computed<number>(() => Math.max(0, ...this.activitySeries()));

  readonly roles = computed<any[]>(() => {
    const r = this.data()?.roles ?? [];
    const max = Math.max(1, ...r.map((x: any) => x.count));
    return r.map((x: any) => ({label: ROLE_LABELS[x.role] ?? x.role, count: x.count, pct: Math.round((x.count / max) * 100)}));
  });

  readonly institutions = computed<any[]>(() => this.data()?.institutions ?? []);
  readonly plans = computed<any[]>(() => this.data()?.plans ?? []);
  readonly totals = computed<any>(() => this.data()?.totals ?? null);

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

  scoreTone(pct: number | null): string {
    if (pct === null || pct === undefined) return 'secondary';
    return pct >= 70 ? 'success' : pct >= 50 ? 'warning' : 'danger';
  }
}
