import {Component, computed, inject, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {forkJoin, of} from 'rxjs';
import {catchError} from 'rxjs/operators';
import {AuthService} from '../../../../../common/auth/auth.service';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, LineChart, StackedBar, BarSeries, StatusBadge} from '../../../../../common/ui';
import {Tone} from '../../../../../common/ui/ui-types';

const STATUS_TONE: Record<string, Tone> = {'Good': 'success', 'Monitor': 'info', 'Needs attention': 'danger'};

@Component({
  selector: 'app-school-report',
  standalone: true,
  imports: [PageHeader, Icon, RouterLink, KpiStrip, LineChart, StackedBar, StatusBadge],
  templateUrl: './school-report.html',
  styleUrl: './school-report.css',
})
export class SchoolReport {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastrService);

  readonly statusTones = STATUS_TONE;
  loading = signal(true);
  exporting = signal(false);
  report = signal<any | null>(null);
  trend = signal<any[]>([]);
  root = signal('/admin');

  readonly kpis = computed<KpiItem[]>(() => {
    const k = this.report()?.kpis;
    if (!k) return [];
    return [
      {label: 'School average', value: k.school_average === null ? '—' : k.school_average + '%', icon: 'trending_up', tone: k.school_average >= 70 ? 'success' : k.school_average >= 50 ? 'warning' : 'danger'},
      {label: 'Total learners', value: k.total_learners, icon: 'group', tone: 'primary'},
      {label: 'Classes analysed', value: k.classes_analysed, icon: 'meeting_room', tone: 'info'},
      {label: 'Subjects analysed', value: k.subjects_analysed, icon: 'subject', tone: 'warning'},
      {label: 'Attendance average', value: k.attendance_average === null ? '—' : k.attendance_average + '%', icon: 'calendar_month', tone: 'success'},
      {label: 'Report completion', value: k.report_completion === null ? '—' : k.report_completion + '%', icon: 'assignment_turned_in', tone: 'primary'},
    ];
  });

  readonly trendSeries = computed<number[]>(() => this.trend().map((m: any) => m.average ?? 0));
  readonly trendLabels = computed<string[]>(() => this.trend().map((m: any) => this.monthLabel(m.month)));

  readonly subjectLabels = computed<string[]>(() => (this.report()?.subject_summary ?? []).map((s: any) => s.subject));
  readonly subjectSeries = computed<BarSeries[]>(() => [
    {label: 'School average %', tone: 'primary', values: (this.report()?.subject_summary ?? []).map((s: any) => s.school_average)},
  ]);

  readonly subjectSummary = computed<any[]>(() => this.report()?.subject_summary ?? []);
  readonly priorityAreas = computed<string[]>(() => this.report()?.priority_areas ?? []);
  readonly topClass = computed<any>(() => this.report()?.top_class ?? null);
  readonly interventionsResolved = computed<number>(() => this.report()?.interventions_resolved ?? 0);

  constructor() {
    this.root.set(this.auth.getAuthSession()?.user?.role === 'tutor_admin' ? '/academy' : '/admin');
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    forkJoin({
      report: this.api.get<any>('/backend/analytics/school-report').pipe(catchError(() => of(null))),
      overview: this.api.get<any>('/backend/analytics/overview').pipe(catchError(() => of(null))),
    }).subscribe((res) => {
      this.report.set(res.report);
      this.trend.set(res.overview?.performance_trend ?? []);
      this.loading.set(false);
    });
  }

  statusTone(s: string): string { return STATUS_TONE[s] ?? 'secondary'; }
  monthLabel(ym: string): string {
    const [y, m] = (ym ?? '').split('-').map(Number);
    if (!y || !m) return ym;
    return new Date(y, m - 1, 1).toLocaleString('en', {month: 'short'});
  }

  exportCsv(): void {
    this.exporting.set(true);
    this.api.get('/backend/export/summary', {responseType: 'blob', observe: 'body'}).subscribe({
      next: (blob: any) => { this.downloadBlob(blob, 'school-report.csv'); this.exporting.set(false); },
      error: () => { this.exporting.set(false); this.toast.error('Could not export the report'); },
    });
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
