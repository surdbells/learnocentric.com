import {Component, computed, inject, input, signal} from '@angular/core';
import {DatePipe, TitleCasePipe} from '@angular/common';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, StatRing, TabBar, TabItem} from '../../../../../common/ui';

const SOURCE_ICON: Record<string, string> = {quiz: 'quiz', worksheet: 'assignment', portfolio: 'folder_special', general: 'chat'};

/**
 * Learner Feedback breakdown (design: Feedback_LD), KPI strip, source tabs,
 * a recent-feedback list, a structured detail panel (did-well / improve /
 * common-error / tutor-comment / next-step + score + marked work) and a rail
 * (reviewed summary + teacher-rated focus areas + quick actions).
 */
@Component({
  selector: 'app-my-feedback',
  standalone: true,
  imports: [Icon, PageHeader, DatePipe, TitleCasePipe, RouterLink, KpiStrip, TabBar, StatRing],
  templateUrl: './my-feedback.html',
  styleUrl: './my-feedback.css',
})
export class MyFeedback {
  /** When hosted inside the merged Progress & Feedback page, hide the page header. */
  readonly embedded = input<boolean>(false);

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  notes = signal<any[]>([]);
  meta = signal<any>({});
  activeTab = signal<string>('all');
  selected = signal<any | null>(null);

  readonly kpis = computed<KpiItem[]>(() => {
    const m = this.meta();
    return [
      {label: 'Total Feedback', value: this.notes().length, icon: 'forum', tone: 'primary', sublabel: 'Across all subjects'},
      {label: 'Needs Action', value: m.unread ?? 0, icon: 'error', tone: (m.unread ? 'warning' : 'secondary'), sublabel: 'Awaiting your review'},
      {label: 'Reviewed', value: m.reviewed ?? 0, icon: 'check_circle', tone: 'success', sublabel: 'Marked as reviewed'},
      {label: 'Average Performance', value: m.avg_performance == null ? '-' : m.avg_performance + '%', icon: 'star', tone: 'info', sublabel: 'Across scored feedback'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const n = this.notes();
    const bySource = (s: string) => n.filter(x => x.source_type === s).length;
    return [
      {key: 'all', label: 'All Feedback', count: n.length},
      {key: 'quiz', label: 'Quiz', count: bySource('quiz')},
      {key: 'worksheet', label: 'Worksheet', count: bySource('worksheet')},
      {key: 'portfolio', label: 'Portfolio', count: bySource('portfolio')},
      {key: 'reviewed', label: 'Reviewed', count: n.filter(x => x.acknowledged).length},
      {key: 'needs_action', label: 'Needs Action', count: n.filter(x => !x.acknowledged).length},
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), n = this.notes();
    if (t === 'all') return n;
    if (t === 'reviewed') return n.filter(x => x.acknowledged);
    if (t === 'needs_action') return n.filter(x => !x.acknowledged);
    return n.filter(x => x.source_type === t);
  });

  readonly focusAreas = computed<any[]>(() => this.meta()?.focus_areas ?? []);

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/feedback/mine').subscribe({
      next: (res) => {
        const data = res?.data ?? [];
        this.notes.set(data);
        this.meta.set(res?.meta ?? {});
        if (!this.selected() || !data.find((d: any) => d.id === this.selected()?.id)) {
          this.selected.set(data[0] ?? null);
        }
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load your feedback'); },
    });
  }

  select(note: any): void {
    this.selected.set(note);
    if (!note.acknowledged) this.acknowledge(note);
  }

  acknowledge(note: any): void {
    this.api.post<any>(`/backend/assessment/feedback/${note.id}/acknowledge`, {}).subscribe({
      next: () => this.load(),
      error: () => this.toast.error('Could not update'),
    });
  }

  sourceIcon(s: string | null): string { return SOURCE_ICON[s ?? 'general'] ?? 'chat'; }
  scoreTone(v: number | null): string {
    if (v == null) return 'secondary';
    if (v >= 70) return 'success';
    if (v >= 50) return 'warning';
    return 'danger';
  }
  barTone(v: number): string { return v >= 70 ? 'success' : v >= 55 ? 'warning' : 'danger'; }

  reviewedPct = computed(() => this.meta()?.reviewed_pct ?? 0);
  reviewedCount = computed(() => this.meta()?.reviewed ?? 0);
  needsReview = computed(() => this.meta()?.unread ?? 0);
}
