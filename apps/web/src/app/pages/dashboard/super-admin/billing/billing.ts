import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {KpiItem, KpiStrip, SparkBar, StatusBadge, TabBar, TabItem} from '../../../../common/ui';
import {Tone} from '../../../../common/ui/ui-types';

const INVOICE_TONE: Record<string, Tone> = {success: 'success', failed: 'danger', pending: 'warning'};
const SUB_TONE: Record<string, Tone> = {active: 'success', grace: 'warning', expired: 'danger', cancelled: 'secondary'};

@Component({
  selector: 'app-super-admin-billing',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, KpiStrip, SparkBar, TabBar, StatusBadge],
  templateUrl: './billing.html',
  styleUrl: './billing.css',
})
export class SuperAdminBilling {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  readonly invoiceTones = INVOICE_TONE;
  readonly subTones = SUB_TONE;

  loading = signal(true);
  overview = signal<any | null>(null);
  invoices = signal<any[]>([]);
  activeTab = signal<string>('all');
  exporting = signal(false);

  readonly stats = computed<any>(() => this.overview()?.stats ?? null);

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.stats();
    if (!s) return [];
    const trend = (this.overview()?.revenue_trend ?? []).map((m: any) => m.collected);
    return [
      {label: 'MRR', value: this.naira(s.mrr_naira), sublabel: this.naira(s.arr_naira) + ' ARR', icon: 'payments', tone: 'success', spark: trend},
      {label: 'Active subscriptions', value: s.active_subscriptions, sublabel: `${s.paying_institutions}/${s.total_institutions} schools`, icon: 'verified', tone: 'primary'},
      {label: 'Collected', value: this.naira(s.collected_naira), icon: 'receipt', tone: 'info'},
      {label: 'Failed payments', value: s.failed_payments, icon: 'cancel', tone: s.failed_payments > 0 ? 'danger' : 'secondary'},
    ];
  });

  readonly revenueSeries = computed<number[]>(() => (this.overview()?.revenue_trend ?? []).map((m: any) => m.collected));
  readonly revenueMonths = computed<any[]>(() => (this.overview()?.revenue_trend ?? []).map((m: any) => ({...m, label: this.monthLabel(m.month)})));
  readonly byPlan = computed<any[]>(() => this.overview()?.by_plan ?? []);
  readonly renewals = computed<any[]>(() => this.overview()?.renewals ?? []);
  readonly issues = computed<any>(() => this.overview()?.payment_issues ?? {subscriptions: [], failed_transactions: []});
  readonly hasIssues = computed<boolean>(() => {
    const i = this.issues();
    return (i.subscriptions?.length ?? 0) > 0 || (i.failed_transactions?.length ?? 0) > 0;
  });

  readonly tabs = computed<TabItem[]>(() => {
    const inv = this.invoices();
    return [
      {key: 'all', label: 'All', count: inv.length},
      {key: 'success', label: 'Paid', count: inv.filter(x => x.status === 'success').length},
      {key: 'failed', label: 'Failed', count: inv.filter(x => x.status === 'failed').length},
      {key: 'pending', label: 'Pending', count: inv.filter(x => x.status === 'pending').length},
    ];
  });

  readonly filteredInvoices = computed<any[]>(() => {
    const t = this.activeTab(), inv = this.invoices();
    return t === 'all' ? inv : inv.filter(x => x.status === t);
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/platform/billing/overview').subscribe({
      next: (r) => this.overview.set(r),
      error: () => this.toast.error('Could not load billing overview'),
    });
    this.api.get<any>('/backend/platform/billing/invoices').subscribe({
      next: (r) => { this.invoices.set(r?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load invoices'); },
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

  renewalTone(days: number): string { return days < 0 ? 'danger' : days <= 7 ? 'warning' : 'success'; }
  renewalLabel(days: number): string {
    if (days < 0) return `${Math.abs(days)}d overdue`;
    if (days === 0) return 'Due today';
    return `${days}d left`;
  }

  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
