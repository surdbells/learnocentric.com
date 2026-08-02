import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {KpiItem, KpiStrip, StatusBadge, TabBar, TabItem, Tone} from '../../../../common/ui';

/**
 * Platform audit trail (super admin) — read-only view of the AuditLog rows the
 * app already writes, with derived category + risk, KPI totals, category tabs,
 * search and pagination (all server-driven via /backend/audit-logs).
 */
@Component({
  selector: 'app-super-admin-audit-logs',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, FormsModule, KpiStrip, TabBar, StatusBadge],
  templateUrl: './audit-logs.html',
  styleUrl: './audit-logs.css',
})
export class SuperAdminAuditLogs {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  rows = signal<any[]>([]);
  stats = signal<any>({total: 0, high_risk: 0, failed_logins: 0, categories: []});
  meta = signal<any>({total: 0, page: 1, per_page: 25});
  category = signal<string>('all');
  search = signal<string>('');
  page = signal<number>(1);

  readonly riskMap: Record<string, Tone> = {high: 'danger', medium: 'warning', low: 'secondary'};

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.stats();
    return [
      {label: 'Total log entries', value: s.total ?? 0, icon: 'news', tone: 'primary'},
      {label: 'High-risk events', value: s.high_risk ?? 0, icon: 'shield', tone: s.high_risk ? 'danger' : 'secondary'},
      {label: 'Failed logins', value: s.failed_logins ?? 0, icon: 'cancel', tone: s.failed_logins ? 'warning' : 'secondary'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const cats = this.stats()?.categories ?? [];
    return [
      {key: 'all', label: 'All', count: this.stats()?.total ?? 0},
      ...cats.map((c: any) => ({key: c.category, label: this.titleCase(c.category), count: c.count})),
    ];
  });

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    const params: any = {page: this.page(), per_page: 25};
    if (this.category() !== 'all') params.category = this.category();
    if (this.search().trim()) params.q = this.search().trim();
    this.api.get<any>('/backend/audit-logs', {params}).subscribe({
      next: (res) => {
        this.rows.set(res?.data ?? []);
        this.meta.set(res?.meta ?? this.meta());
        if (res?.stats) this.stats.set(res.stats);
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load audit logs'); },
    });
  }

  setCategory(k: string): void { this.category.set(k); this.page.set(1); this.load(); }
  runSearch(): void { this.page.set(1); this.load(); }
  prev(): void { if (this.page() > 1) { this.page.set(this.page() - 1); this.load(); } }
  next(): void { if (this.page() * (this.meta().per_page || 25) < (this.meta().total || 0)) { this.page.set(this.page() + 1); this.load(); } }

  totalPages(): number { const m = this.meta(); return Math.max(1, Math.ceil((m.total || 0) / (m.per_page || 25))); }
  riskColor(r: string): Tone { return this.riskMap[r] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
