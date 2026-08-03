import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {KpiItem, KpiStrip, StatusBadge, TabBar, TabItem} from '../../../../common/ui';
import {Tone} from '../../../../common/ui/ui-types';

const SEVERITY_TONE: Record<string, Tone> = {low: 'secondary', medium: 'info', high: 'warning', critical: 'danger'};
const STATUS_TONE: Record<string, Tone> = {reported: 'secondary', under_review: 'info', escalated: 'danger', closed: 'success'};

@Component({
  selector: 'app-super-admin-safeguarding',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, FormsModule, KpiStrip, TabBar, StatusBadge],
  templateUrl: './safeguarding.html',
  styleUrl: './safeguarding.css',
})
export class SuperAdminSafeguarding {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  readonly statusTones = STATUS_TONE;
  readonly severityTones = SEVERITY_TONE;
  readonly statuses = ['reported', 'under_review', 'escalated', 'closed'];
  readonly severities = ['low', 'medium', 'high', 'critical'];

  mode = signal<'list' | 'view'>('list');
  loading = signal(true);
  saving = signal(false);
  overview = signal<any | null>(null);
  cases = signal<any[]>([]);
  current = signal<any | null>(null);
  activeTab = signal<string>('all');

  // Triage form (bound in the detail view)
  triage = {status: '', severity: '', outcome: ''};

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.overview()?.stats; const c = this.overview()?.compliance;
    if (!s) return [];
    const cov = c && c.total ? Math.round((c.with_lead / c.total) * 100) : null;
    return [
      {label: 'Open cases', value: s.open, icon: 'shield', tone: s.open > 0 ? 'warning' : 'success'},
      {label: 'Escalated', value: s.escalated, icon: 'campaign', tone: s.escalated > 0 ? 'danger' : 'secondary'},
      {label: 'Critical (open)', value: s.critical_open, icon: 'cancel', tone: s.critical_open > 0 ? 'danger' : 'secondary'},
      {label: 'Lead coverage', value: cov === null ? '—' : cov + '%', sublabel: c ? `${c.with_lead}/${c.total} schools` : '', icon: 'verified', tone: cov === 100 ? 'success' : cov === null ? 'secondary' : 'warning'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const all = this.cases();
    return [
      {key: 'all', label: 'All', count: all.length},
      ...this.statuses.map(st => ({key: st, label: this.statusLabel(st), count: all.filter(c => c.status === st).length})),
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), all = this.cases();
    return t === 'all' ? all : all.filter(c => c.status === t);
  });

  readonly institutions = computed<any[]>(() => this.overview()?.institutions ?? []);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/platform/safeguarding/overview').subscribe({
      next: (r) => this.overview.set(r),
      error: () => this.toast.error('Could not load safeguarding overview'),
    });
    this.api.get<any>('/backend/platform/safeguarding/cases').subscribe({
      next: (r) => { this.cases.set(r?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load safeguarding cases'); },
    });
  }

  view(c: any): void {
    this.current.set(c);
    this.triage = {status: c.status, severity: c.severity, outcome: c.outcome ?? ''};
    this.mode.set('view');
  }

  saveTriage(): void {
    const c = this.current();
    if (!c) return;
    this.saving.set(true);
    this.api.put<any>(`/backend/platform/safeguarding/${c.id}`, this.triage).subscribe({
      next: (updated) => {
        this.saving.set(false);
        this.toast.success('Case updated');
        // reflect the change in the list + reload aggregates
        this.cases.set(this.cases().map(x => x.id === updated.id ? updated : x));
        this.current.set(updated);
        this.refreshOverview();
      },
      error: (e) => { this.saving.set(false); this.toast.error(e?.error?.error || 'Could not update case'); },
    });
  }

  backToList(): void {
    this.current.set(null);
    this.mode.set('list');
  }

  private refreshOverview(): void {
    this.api.get<any>('/backend/platform/safeguarding/overview').subscribe({next: (r) => this.overview.set(r)});
  }

  statusTone(s: string): string { return STATUS_TONE[s] ?? 'secondary'; }
  severityTone(s: string): string { return SEVERITY_TONE[s] ?? 'secondary'; }
  statusLabel(s: string): string { return s === 'under_review' ? 'Under review' : s.charAt(0).toUpperCase() + s.slice(1); }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : ''; }
}
