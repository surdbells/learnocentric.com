import {Component, computed, inject, signal} from '@angular/core';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {
  KpiItem, KpiStrip, LineChart, DonutChart, DonutSegment, StackedBar, BarSeries, StatusBadge,
} from '../../../../../common/ui';
import {Tone} from '../../../../../common/ui/ui-types';

const MASTERY_TONE: Record<string, Tone> = {Strong: 'success', Good: 'primary', Developing: 'warning', Weak: 'danger'};

@Component({
  selector: 'app-analytics',
  standalone: true,
  imports: [PageHeader, Icon, KpiStrip, LineChart, DonutChart, StackedBar, StatusBadge],
  templateUrl: './analytics.html',
  styleUrl: './analytics.css',
})
export class Analytics {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  readonly masteryTones = MASTERY_TONE;
  loading = signal(true);
  exporting = signal(false);
  data = signal<any | null>(null);

  readonly quizBySubject = computed<any[]>(() => this.data()?.quiz_by_subject ?? []);
  readonly topicMastery = computed<any[]>(() => this.data()?.topic_mastery ?? []);
  readonly learnersAttention = computed<any[]>(() => this.data()?.learners_attention ?? []);

  /** Weighted average class score across subjects. */
  readonly avgClassScore = computed<number | null>(() => {
    const rows = this.quizBySubject();
    if (!rows.length) return null;
    let sum = 0, n = 0;
    for (const r of rows) { sum += r.average * r.attempts; n += r.attempts; }
    return n ? Math.round(sum / n) : null;
  });

  readonly portfolioCompletion = computed<number>(() => {
    const p = this.data()?.portfolio;
    if (!p) return 0;
    const reviewed = Object.values(p.ratings ?? {}).reduce((s: number, v: any) => s + v, 0);
    const total = reviewed + (p.pending ?? 0);
    return total ? Math.round((reviewed / total) * 100) : 0;
  });

  readonly kpis = computed<KpiItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const avg = this.avgClassScore();
    return [
      {label: 'Average class score', value: avg === null ? '-' : avg + '%', icon: 'trending_up', tone: avg !== null && avg >= 70 ? 'success' : avg !== null && avg >= 50 ? 'warning' : 'danger'},
      {label: 'Learners below mastery', value: this.learnersAttention().length, sublabel: 'flagged', icon: 'group', tone: this.learnersAttention().length ? 'danger' : 'success'},
      {label: 'Portfolio completion', value: this.portfolioCompletion() + '%', icon: 'folder_special', tone: 'info'},
      {label: 'Feedback ack rate', value: d.feedback?.ack_rate === null ? '-' : (d.feedback?.ack_rate ?? 0) + '%', icon: 'forum', tone: 'primary'},
    ];
  });

  // Performance trend line
  readonly trendSeries = computed<number[]>(() => (this.data()?.performance_trend ?? []).map((m: any) => m.average ?? 0));
  readonly trendLabels = computed<string[]>(() => (this.data()?.performance_trend ?? []).map((m: any) => this.monthLabel(m.month)));

  // Class performance by subject (bars)
  readonly subjectLabels = computed<string[]>(() => this.quizBySubject().map((q: any) => q.subject));
  readonly subjectSeries = computed<BarSeries[]>(() => [
    {label: 'Average %', tone: 'primary', values: this.quizBySubject().map((q: any) => q.average)},
  ]);

  // Mastery distribution donut
  readonly masteryDonut = computed<DonutSegment[]>(() => {
    const m = this.data()?.mastery_distribution;
    if (!m) return [];
    const segs: DonutSegment[] = [
      {label: 'Strong (80-100%)', value: m.strong, tone: 'success'},
      {label: 'Good (60-79%)', value: m.good, tone: 'primary'},
      {label: 'Developing (40-59%)', value: m.developing, tone: 'warning'},
      {label: 'Weak (0-39%)', value: m.weak, tone: 'danger'},
    ];
    return segs.filter(s => s.value > 0);
  });

  constructor() {
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/analytics/overview').subscribe({
      next: (res) => { this.data.set(res); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load analytics'); },
    });
  }

  masteryTone(band: string): string { return MASTERY_TONE[band] ?? 'secondary'; }
  monthLabel(ym: string): string {
    const [y, m] = (ym ?? '').split('-').map(Number);
    if (!y || !m) return ym;
    return new Date(y, m - 1, 1).toLocaleString('en', {month: 'short'});
  }
  pct(v: number | null): string { return v === null || v === undefined ? '-' : v + '%'; }

  exportCsv(): void {
    this.exporting.set(true);
    this.api.get('/backend/export/summary', {responseType: 'blob', observe: 'body'}).subscribe({
      next: (blob: any) => { this.downloadBlob(blob, 'school-summary.csv'); this.exporting.set(false); },
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
