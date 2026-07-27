import {afterNextRender, Component, computed, ElementRef, inject, signal, ViewChild} from '@angular/core';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {
  AttentionItem, AttentionList, KpiItem, KpiStrip, ProgressCell, QuickAction, QuickActions, RailCard,
} from '../../../../../common/ui';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, KpiStrip, RailCard, AttentionList, QuickActions, ProgressCell],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class AdminDashboard {
  @ViewChild('quizChart') quizChart!: ElementRef<HTMLDivElement>;

  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);

  loading = signal(true);
  data = signal<any | null>(null);
  firstName = signal('');
  root = signal('/admin');
  base = computed(() => `${this.root()}/academics`);
  mgmt = computed(() => `${this.root()}/management`);
  comm = computed(() => `${this.root()}/communication`);
  private chart: any = null;

  constructor() {
    const user = this.auth.getAuthSession()?.user;
    this.firstName.set(user?.firstName ?? 'there');
    this.root.set(user?.role === 'tutor_admin' ? '/academy' : '/admin');
    afterNextRender(() => this.load());
  }

  /** KPI strip — real counts from /dashboard/admin (deltas/sparklines await the Phase-4 trend endpoint). */
  readonly kpis = computed<KpiItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const s = d.stats, b = this.base(), r = this.root();
    return [
      {label: 'Students', value: s.students, icon: 'group', tone: 'primary', link: `${r}/students`},
      {label: 'Teachers', value: s.teachers, icon: 'supervisor_account', tone: 'info', link: `${r}/teachers`},
      {label: 'Subjects', value: s.subjects, icon: 'subject', tone: 'warning', link: `${b}/subjects`},
      {label: 'Classes', value: s.classes, icon: 'meeting_room', tone: 'success', link: `${b}/classes`},
      {label: 'Published topics', value: s.published_topics, icon: 'menu_book', tone: 'primary', link: `${b}/topics`},
      {label: 'Live classes', value: s.live_classes, icon: 'video_camera_front', tone: 'danger', link: `${b}/live-classes`},
    ];
  });

  readonly attention = computed<AttentionItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const a = d.action_items, b = this.base(), m = this.mgmt();
    return [
      {label: 'Worksheets to grade', count: a.worksheets_to_grade, tone: a.worksheets_to_grade ? 'warning' : 'secondary', icon: 'assignment_turned_in', link: `${b}/worksheets`},
      {label: 'Portfolio to review', count: a.portfolio_to_review, tone: a.portfolio_to_review ? 'primary' : 'secondary', icon: 'folder_special', link: `${b}/portfolio`},
      {label: 'Open interventions', count: a.interventions_open, tone: a.interventions_open ? 'info' : 'secondary', icon: 'support', link: `${b}/interventions`},
      {label: 'Open safeguarding cases', count: a.safeguarding_open, tone: a.safeguarding_open ? 'danger' : 'secondary', icon: 'shield', link: `${m}/safeguarding`},
    ];
  });

  readonly quickActions = computed<QuickAction[]>(() => {
    const r = this.root(), b = this.base(), c = this.comm();
    return [
      {label: 'Add Learner', sublabel: 'Enrol a new student', icon: 'person_add', link: `${r}/students/new`},
      {label: 'Add Teacher', sublabel: 'Onboard staff', icon: 'supervisor_account', link: `${r}/teachers/new`},
      {label: 'Schedule Live Class', sublabel: 'Plan a session', icon: 'video_camera_front', link: `${b}/live-classes`},
      {label: 'Send Announcement', sublabel: 'Notify the school', icon: 'campaign', link: `${c}/announcements`},
    ];
  });

  /** Top performing subjects, derived from quiz averages. */
  readonly topSubjects = computed<Array<{subject: string; average: number}>>(() => {
    const rows = this.data()?.quiz_by_subject ?? [];
    return [...rows].sort((a, b) => b.average - a.average).slice(0, 5);
  });

  refresh(): void { this.loading.set(true); this.load(); }

  private load(): void {
    this.api.get<any>('/backend/dashboard/admin').subscribe({
      next: (res) => { this.data.set(res); this.loading.set(false); setTimeout(() => this.renderChart(res)); },
      error: () => this.loading.set(false),
    });
  }

  private async renderChart(d: any): Promise<void> {
    if (!this.quizChart?.nativeElement || !d.quiz_by_subject?.length) return;
    const {default: ApexCharts} = await import('apexcharts');
    this.chart?.destroy();
    this.chart = new ApexCharts(this.quizChart.nativeElement, {
      chart: {type: 'bar', height: 280, toolbar: {show: false}, fontFamily: 'inherit'},
      series: [{name: 'Average', data: d.quiz_by_subject.map((q: any) => q.average)}],
      xaxis: {categories: d.quiz_by_subject.map((q: any) => q.subject)},
      yaxis: {max: 100},
      colors: ['#39c645'],
      plotOptions: {bar: {borderRadius: 6, columnWidth: '45%'}},
      dataLabels: {enabled: true, formatter: (v: number) => v + '%'},
      grid: {borderColor: 'rgba(128,128,128,.15)'},
      tooltip: {y: {formatter: (v: number) => v + '%'}},
    });
    this.chart.render();
  }

  pct(v: number | null): string { return v === null || v === undefined ? '—' : v + '%'; }
}
