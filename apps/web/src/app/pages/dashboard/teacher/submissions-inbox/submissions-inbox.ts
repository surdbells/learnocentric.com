import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../common/icon/icon';
import {ApiService} from '../../../../common/service/api.service';
import {AuthService} from '../../../../common/auth/auth.service';
import {KpiStrip, KpiItem, TabBar, TabItem, DonutChart, DonutSegment} from '../../../../common/ui';

/**
 * Unified grading inbox, every worksheet + portfolio submission awaiting review
 * in one place, with headline counts and a jump-to-grade action. Backed by
 * GET /assessment/submissions/inbox.
 */
@Component({
  selector: 'app-submissions-inbox',
  standalone: true,
  imports: [PageHeader, Icon, RouterLink, DatePipe, FormsModule, KpiStrip, TabBar, DonutChart],
  templateUrl: './submissions-inbox.html',
  styleUrl: './submissions-inbox.css',
})
export class SubmissionsInbox {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  data = signal<any | null>(null);
  typeTab = signal<string>('all');
  search = signal<string>('');
  subjectFilter = signal<string>('');

  /** Grading pages live under the current role's tree. */
  readonly base = this.auth.getAuthSession()?.user?.role?.includes('admin') ? '/admin' : '/teacher';

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/submissions/inbox').subscribe({
      next: (res) => { this.data.set(res); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the submissions inbox'); },
    });
  }

  readonly items = computed<any[]>(() => this.data()?.items ?? []);

  readonly kpis = computed<KpiItem[]>(() => {
    const k = this.data()?.kpis;
    if (!k) return [];
    return [
      {label: 'Pending review', value: k.pending_review, icon: 'pending_actions', tone: k.pending_review ? 'warning' : 'success'},
      {label: 'Submitted today', value: k.submitted_today, icon: 'today', tone: 'info'},
      {label: 'Worksheets to grade', value: k.worksheets_to_grade, icon: 'assignment', tone: 'primary'},
      {label: 'Portfolio to review', value: k.portfolio_to_review, icon: 'folder_special', tone: 'info'},
      {label: 'Overdue', value: k.overdue, icon: 'schedule', tone: k.overdue ? 'danger' : 'secondary'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const items = this.items();
    return [
      {key: 'all', label: 'All', count: items.length},
      {key: 'worksheet', label: 'Worksheets', count: items.filter(i => i.type === 'worksheet').length},
      {key: 'portfolio', label: 'Portfolio', count: items.filter(i => i.type === 'portfolio').length},
    ];
  });

  readonly subjectOptions = computed<string[]>(() =>
    [...new Set(this.items().map(i => i.subject).filter(Boolean))] as string[]);

  readonly donut = computed<DonutSegment[]>(() => (this.data()?.breakdown ?? []).map((b: any) => ({label: b.label, value: b.value, tone: b.tone})));

  readonly filtered = computed<any[]>(() => {
    const type = this.typeTab();
    const subj = this.subjectFilter();
    const term = this.search().toLowerCase().trim();
    return this.items().filter((i: any) => {
      if (type !== 'all' && i.type !== type) return false;
      if (subj && i.subject !== subj) return false;
      if (!term) return true;
      return (i.learner || '').toLowerCase().includes(term)
        || (i.topic || '').toLowerCase().includes(term)
        || (i.title || '').toLowerCase().includes(term);
    });
  });

  /** Where "Grade" lands, the relevant grading page for the submission type. */
  gradeLink(item: any): string {
    return item.type === 'worksheet' ? `${this.base}/academics/worksheets` : `${this.base}/academics/portfolio`;
  }
}
