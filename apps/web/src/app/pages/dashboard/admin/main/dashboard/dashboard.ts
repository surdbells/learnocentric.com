import {Component, computed, inject, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {forkJoin, of} from 'rxjs';
import {catchError} from 'rxjs/operators';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {
  AttentionItem, AttentionList, KpiItem, KpiStrip, QuickAction, QuickActions,
  DonutChart, DonutSegment, StackedBar, BarSeries, BarList, BarItem,
} from '../../../../../common/ui';

interface ActivityRow { label: string; value: number; icon: string; }

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, DatePipe, KpiStrip, AttentionList, QuickActions, DonutChart, StackedBar, BarList],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class AdminDashboard {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  data = signal<any | null>(null);
  events = signal<any[]>([]);
  firstName = signal('');
  root = signal('/admin');
  base = computed(() => `${this.root()}/academics`);
  mgmt = computed(() => `${this.root()}/management`);
  comm = computed(() => `${this.root()}/communication`);

  constructor() {
    const user = this.auth.getAuthSession()?.user;
    this.firstName.set(user?.firstName ?? 'there');
    this.root.set(user?.role === 'tutor_admin' ? '/academy' : '/admin');
    if (isPlatformBrowser(this.platformId)) this.load();
    else this.loading.set(false);
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const s = d.stats, q = d.quiz, b = this.base(), r = this.root();
    return [
      {label: 'Total learners', value: s.students, icon: 'group', tone: 'primary', link: `${r}/students`},
      {label: 'Total teachers', value: s.teachers, icon: 'supervisor_account', tone: 'info', link: `${r}/teachers`},
      {label: 'Total classes', value: s.classes, icon: 'meeting_room', tone: 'success', link: `${b}/classes`},
      {label: 'Subjects offered', value: s.subjects, icon: 'subject', tone: 'warning', link: `${b}/subjects`},
      {label: 'Pass rate', value: q.pass_rate === null ? '—' : q.pass_rate + '%', sublabel: q.attempts + ' attempts', icon: 'check_circle', tone: 'success'},
      {label: 'Overall performance', value: q.average === null ? '—' : q.average + '%', icon: 'trending_up', tone: q.average >= 70 ? 'success' : q.average >= 50 ? 'warning' : 'danger'},
    ];
  });

  // Academic performance — average per subject (vertical bars)
  readonly academicLabels = computed<string[]>(() => (this.data()?.quiz_by_subject ?? []).map((q: any) => q.subject));
  readonly academicSeries = computed<BarSeries[]>(() => [
    {label: 'Average %', tone: 'primary', values: (this.data()?.quiz_by_subject ?? []).map((q: any) => q.average)},
  ]);

  // Curriculum coverage donut
  readonly coverageDonut = computed<DonutSegment[]>(() => {
    const c = this.data()?.curriculum_coverage;
    if (!c) return [];
    const segs: DonutSegment[] = [
      {label: 'On track', value: c.on_track, tone: 'success'},
      {label: 'Behind', value: c.behind, tone: 'warning'},
      {label: 'At risk', value: c.at_risk, tone: 'danger'},
    ];
    return segs.filter(s => s.value > 0);
  });
  readonly coveragePct = computed<number>(() => this.data()?.curriculum_coverage?.coverage_pct ?? 0);

  // Top performing subjects
  readonly topSubjects = computed<BarItem[]>(() => {
    const rows = this.data()?.quiz_by_subject ?? [];
    return [...rows].sort((a: any, b: any) => b.average - a.average).slice(0, 6)
      .map((q: any) => ({label: q.subject, value: q.average, tone: q.average >= 70 ? 'success' : q.average >= 50 ? 'warning' : 'danger'}));
  });

  readonly teacherActivity = computed<ActivityRow[]>(() => {
    const a = this.data()?.teacher_activity;
    if (!a) return [];
    return [
      {label: 'Delivery packs published', value: a.delivery_packs, icon: 'layers'},
      {label: 'Live classes', value: a.live_classes, icon: 'video_camera_front'},
      {label: 'Assessments published', value: a.assessments, icon: 'quiz'},
      {label: 'Worksheets graded', value: a.worksheets_graded, icon: 'assignment_turned_in'},
      {label: 'Feedback given', value: a.feedback_given, icon: 'forum'},
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

  readonly upcomingEvents = computed<any[]>(() => this.events().slice(0, 4));

  readonly quickActions = computed<QuickAction[]>(() => {
    const r = this.root(), b = this.base(), c = this.comm();
    return [
      {label: 'Add Learner', sublabel: 'Enrol a new student', icon: 'person_add', link: `${r}/students/new`},
      {label: 'Add Teacher', sublabel: 'Onboard staff', icon: 'supervisor_account', link: `${r}/teachers/new`},
      {label: 'Schedule Live Class', sublabel: 'Plan a session', icon: 'video_camera_front', link: `${b}/live-classes`},
      {label: 'Send Announcement', sublabel: 'Notify the school', icon: 'campaign', link: `${c}/announcements`},
    ];
  });

  refresh(): void { this.loading.set(true); this.load(); }

  private load(): void {
    forkJoin({
      dash: this.api.get<any>('/backend/dashboard/admin').pipe(catchError(() => of(null))),
      cal: this.api.get<any>('/backend/school/calendar').pipe(catchError(() => of(null))),
    }).subscribe((res) => {
      this.data.set(res.dash);
      const ev = res.cal?.events ?? res.cal?.data ?? (Array.isArray(res.cal) ? res.cal : []);
      this.events.set(ev);
      this.loading.set(false);
    });
  }

  pct(v: number | null): string { return v === null || v === undefined ? '—' : v + '%'; }
}
