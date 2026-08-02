import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';

/** Icon per report template, so the cards read at a glance. */
const TEMPLATE_ICON: Record<string, string> = {
  platform_overview: 'insights', institution_performance: 'apartment',
  subscriptions: 'payments', user_growth: 'trending_up',
};

@Component({
  selector: 'app-super-admin-reports',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe],
  templateUrl: './reports.html',
  styleUrl: './reports.css',
})
export class SuperAdminReports {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'list' | 'view'>('list');
  loading = signal(true);
  templates = signal<any[]>([]);
  reports = signal<any[]>([]);
  current = signal<any | null>(null);
  generating = signal<string | null>(null); // template type being generated
  exporting = signal(false);

  readonly hasHistory = computed(() => this.reports().length > 0);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/platform/reports/templates').subscribe({
      next: (r) => this.templates.set(r?.data ?? []),
      error: () => this.toast.error('Could not load report templates'),
    });
    this.api.get<any>('/backend/platform/reports').subscribe({
      next: (r) => { this.reports.set(r?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load report history'); },
    });
  }

  templateIcon(type: string): string { return TEMPLATE_ICON[type] ?? 'description'; }

  generate(type: string): void {
    this.generating.set(type);
    this.api.post<any>('/backend/platform/reports', {type}).subscribe({
      next: (report) => {
        this.generating.set(null);
        this.toast.success('Report generated');
        this.current.set(report);
        this.mode.set('view');
        this.refreshHistory();
      },
      error: (e) => { this.generating.set(null); this.toast.error(e?.error?.error || 'Could not generate report'); },
    });
  }

  view(row: any): void {
    this.api.get<any>(`/backend/platform/reports/${row.id}`).subscribe({
      next: (report) => { this.current.set(report); this.mode.set('view'); },
      error: () => this.toast.error('Could not open report'),
    });
  }

  exportReport(id: number): void {
    this.exporting.set(true);
    this.api.get(`/backend/platform/reports/${id}/export`, {responseType: 'blob', observe: 'body'}).subscribe({
      next: (blob: any) => { this.downloadBlob(blob, `report-${id}.csv`); this.exporting.set(false); },
      error: () => { this.exporting.set(false); this.toast.error('Could not export report'); },
    });
  }

  remove(row: any): void {
    this.api.delete(`/backend/platform/reports/${row.id}`, {confirm: `Delete the report "${row.title}"? This cannot be undone.`}).subscribe({
      next: () => {
        this.toast.success('Report deleted');
        if (this.current()?.id === row.id) this.backToList();
        this.refreshHistory();
      },
      error: () => this.toast.error('Could not delete report'),
    });
  }

  backToList(): void {
    this.current.set(null);
    this.mode.set('list');
    this.refreshHistory();
  }

  private refreshHistory(): void {
    this.api.get<any>('/backend/platform/reports').subscribe({next: (r) => this.reports.set(r?.data ?? [])});
  }

  private downloadBlob(blob: Blob, filename: string): void {
    if (typeof window === 'undefined') return;
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
  }
}
