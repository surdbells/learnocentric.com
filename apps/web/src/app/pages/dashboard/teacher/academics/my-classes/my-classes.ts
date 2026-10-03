import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {forkJoin} from 'rxjs';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {KpiStrip, KpiItem, DonutChart, DonutSegment} from '../../../../../common/ui';

/**
 * Teacher "My Classes" workspace (design: My Classes_TD), KPI strip, a
 * filterable class table, a performance-distribution donut, today's live
 * schedule, and pending-review panels. Backed by /teacher/classes (real
 * roster + graded-attempt figures) + /dashboard/teacher (pending work).
 * The design's "Attendance" column has no data source, so it is replaced by
 * a real "Assessments" (graded attempts) count rather than fabricated.
 */
@Component({
  selector: 'app-my-classes',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, RouterLink, DatePipe, KpiStrip, DonutChart],
  templateUrl: './my-classes.html',
  styleUrl: './my-classes.css',
})
export class MyClasses {
  private api = inject(ApiService);
  private toast = inject(ToastrService);

  loading = signal(true);
  data = signal<any>(null);
  pending = signal<any[]>([]);
  actionItems = signal<any>({});

  // filters
  subjectFilter = signal('all');
  statusFilter = signal('all');
  search = signal('');

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    forkJoin({
      classes: this.api.get<any>('/backend/teacher/classes'),
      dash: this.api.get<any>('/backend/dashboard/teacher'),
    }).subscribe({
      next: ({classes, dash}) => {
        this.data.set(classes);
        this.pending.set(dash?.pending_submissions ?? []);
        this.actionItems.set(dash?.action_items ?? {});
        this.loading.set(false);
      },
      error: () => { this.toast.error('Could not load your classes'); this.loading.set(false); },
    });
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const k = this.data()?.kpis ?? {};
    return [
      {label: 'Total Classes', value: k.total_classes ?? 0, icon: 'groups', tone: 'primary'},
      {label: 'Total Learners', value: k.total_learners ?? 0, icon: 'school', tone: 'info'},
      {label: 'Class Average', value: k.class_average == null ? '-' : k.class_average + '%', icon: 'trending_up', tone: 'success'},
      {label: 'Classes Today', value: k.classes_today ?? 0, icon: 'event', tone: 'warning'},
      {label: 'Pending Reviews', value: k.pending_reviews ?? 0, icon: 'fact_check', tone: (k.pending_reviews ? 'danger' : 'secondary')},
    ];
  });

  readonly subjects = computed<string[]>(() => {
    const set = new Set<string>();
    (this.data()?.classes ?? []).forEach((c: any) => (c.subject || '').split(',').map((s: string) => s.trim()).filter(Boolean).forEach((s: string) => set.add(s)));
    return [...set].sort();
  });

  readonly filtered = computed<any[]>(() => {
    const q = this.search().toLowerCase().trim();
    const subj = this.subjectFilter(), st = this.statusFilter();
    return (this.data()?.classes ?? []).filter((c: any) => {
      if (subj !== 'all' && !(c.subject || '').includes(subj)) return false;
      if (st !== 'all' && c.status !== st) return false;
      if (q && !(`${c.label} ${c.subject}`.toLowerCase().includes(q))) return false;
      return true;
    });
  });

  readonly donut = computed<DonutSegment[]>(() => {
    const dist = this.data()?.performance_distribution ?? [];
    return dist.filter((d: any) => d.count > 0).map((d: any) => ({label: d.band, value: d.count, tone: d.tone}));
  });

  readonly hasDonut = computed(() => this.donut().reduce((s, d) => s + d.value, 0) > 0);

  /** Distinct learners with work awaiting review (honest source for the attention panel). */
  readonly attentionLearners = computed<any[]>(() => {
    const seen = new Set<string>();
    const out: any[] = [];
    for (const p of this.pending()) {
      if (seen.has(p.learner)) continue;
      seen.add(p.learner);
      out.push(p);
      if (out.length >= 5) break;
    }
    return out;
  });

  avgTone(v: number | null): string {
    if (v == null) return 'text-body-secondary';
    if (v >= 70) return 'text-success';
    if (v >= 50) return 'text-warning';
    return 'text-danger';
  }

  scheduleTone(status: string): string {
    return status === 'live' ? 'success' : status === 'scheduled' ? 'primary' : 'secondary';
  }
}
