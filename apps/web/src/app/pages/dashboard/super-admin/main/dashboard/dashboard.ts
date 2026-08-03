import {Component, computed, inject, PLATFORM_ID, signal} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {forkJoin, of} from 'rxjs';
import {catchError} from 'rxjs/operators';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {
  KpiItem, KpiStrip, QuickAction, QuickActions, LineChart, DonutChart, DonutSegment, StatusBadge,
} from '../../../../../common/ui';

const M = '/super-admin/management';

@Component({
  selector: 'app-super-admin-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, KpiStrip, QuickActions, LineChart, DonutChart, StatusBadge],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class SuperAdminDashboard {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  firstName = signal('');

  analytics = signal<any | null>(null);
  billing = signal<any | null>(null);
  safeguarding = signal<any | null>(null);
  tickets = signal<any[]>([]);

  readonly statusTones: Record<string, any> = {open: 'warning', in_progress: 'info', resolved: 'success', closed: 'secondary'};

  private delta(key: string): {delta?: string; deltaDir?: 'up' | 'down' | 'flat'; deltaLabel?: string} {
    const v = this.analytics()?.deltas?.[key];
    if (v === undefined || v === null) return {};
    return {delta: (v > 0 ? '+' : '') + v + '%', deltaDir: v > 0 ? 'up' : v < 0 ? 'down' : 'flat', deltaLabel: 'vs prev 30d'};
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const t = this.analytics()?.totals; const b = this.billing()?.stats;
    if (!t) return [];
    const admins = (this.analytics()?.roles ?? []).filter((r: any) => ['school_admin', 'tutor_admin'].includes(r.role)).reduce((s: number, r: any) => s + r.count, 0);
    return [
      {label: 'Total schools', value: t.institutions, icon: 'apartment', tone: 'primary', link: `${M}/institutions`, ...this.delta('institutions')},
      {label: 'Active learners', value: t.students, icon: 'group', tone: 'info', ...this.delta('students')},
      {label: 'Active teachers', value: t.teachers, icon: 'supervisor_account', tone: 'success', ...this.delta('teachers')},
      {label: 'School admins', value: admins, icon: 'shield', tone: 'warning'},
      {label: 'Active subscriptions', value: b?.active_subscriptions ?? '—', icon: 'credit_card', tone: 'primary', link: `${M}/billing`},
      {label: 'Renewals due', value: (this.billing()?.renewals ?? []).length, icon: 'schedule', tone: 'danger', link: `${M}/billing`},
    ];
  });

  // Usage trend (monthly active users from analytics growth)
  readonly usageSeries = computed<number[]>(() => (this.analytics()?.growth ?? []).map((m: any) => m.users));
  readonly usageLabels = computed<string[]>(() => {
    const g = this.analytics()?.growth ?? [];
    return g.map((m: any) => this.monthLabel(m.month)).filter((_: any, i: number) => i === 0 || i === Math.floor(g.length / 2) || i === g.length - 1);
  });

  // Subscription status donut (real: active vs grace/expired vs expiring-soon)
  readonly subDonut = computed<DonutSegment[]>(() => {
    const b = this.billing();
    if (!b) return [];
    const issues = b.payment_issues?.subscriptions ?? [];
    const grace = issues.filter((s: any) => s.status === 'grace').length;
    const expired = issues.filter((s: any) => s.status === 'expired').length;
    const dueSoon = (b.renewals ?? []).filter((r: any) => r.days_left >= 0 && r.days_left <= 30).length;
    const active = Math.max((b.stats?.active_subscriptions ?? 0) - dueSoon, 0);
    const segs: DonutSegment[] = [
      {label: 'Active', value: active, tone: 'success'},
      {label: 'Expiring ≤30d', value: dueSoon, tone: 'warning'},
      {label: 'In grace', value: grace, tone: 'info'},
      {label: 'Expired', value: expired, tone: 'danger'},
    ];
    return segs.filter(s => s.value > 0);
  });

  readonly readiness = computed<any>(() => this.analytics()?.content_readiness ?? null);
  readonly revenue = computed<any>(() => this.billing()?.stats ?? null);
  readonly attention = computed<any[]>(() => (this.analytics()?.institutions ?? []).filter((i: any) => i.attempts === 0));
  readonly sgAlerts = computed<any>(() => this.safeguarding()?.stats ?? null);
  readonly recentTickets = computed<any[]>(() => this.tickets().slice(0, 4));

  readonly quickActions: QuickAction[] = [
    {label: 'Onboard school', sublabel: 'Add a new institution', icon: 'apartment', link: `${M}/institutions/onboard`},
    {label: 'Subscription plans', sublabel: 'Manage plans & billing', icon: 'credit_card', link: `${M}/billing`},
    {label: 'Review content', sublabel: 'Pending content packs', icon: 'library_books', link: `${M}/content-library`},
    {label: 'Safeguarding', sublabel: 'Cases & compliance', icon: 'shield', link: `${M}/safeguarding`},
  ];

  readonly mgmt = M;

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'Admin');
    if (!isPlatformBrowser(this.platformId)) { this.loading.set(false); return; }
    forkJoin({
      analytics: this.api.get<any>('/backend/platform/analytics').pipe(catchError(() => of(null))),
      billing: this.api.get<any>('/backend/platform/billing/overview').pipe(catchError(() => of(null))),
      safeguarding: this.api.get<any>('/backend/platform/safeguarding/overview').pipe(catchError(() => of(null))),
      tickets: this.api.get<any>('/backend/support/tickets').pipe(catchError(() => of([]))),
    }).subscribe((res) => {
      this.analytics.set(res.analytics);
      this.billing.set(res.billing);
      this.safeguarding.set(res.safeguarding);
      this.tickets.set(Array.isArray(res.tickets) ? res.tickets : (res.tickets?.data ?? []));
      this.loading.set(false);
    });
  }

  naira(v: number | null | undefined): string {
    if (v === null || v === undefined) return '₦0';
    return '₦' + Number(v).toLocaleString('en-NG');
  }
  monthLabel(ym: string): string {
    const [y, m] = (ym ?? '').split('-').map(Number);
    if (!y || !m) return ym;
    return new Date(y, m - 1, 1).toLocaleString('en', {month: 'short'});
  }
  ticketTone(s: string): string { return this.statusTones[s] ?? 'secondary'; }
}
