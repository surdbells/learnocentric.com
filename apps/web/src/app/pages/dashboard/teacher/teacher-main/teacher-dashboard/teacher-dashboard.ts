import {Component, computed, inject, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {
  AttentionItem, AttentionList, KpiItem, KpiStrip, QuickAction, QuickActions, RailCard, BarList, BarItem,
} from '../../../../../common/ui';

@Component({
  selector: 'app-teacher-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, DatePipe, KpiStrip, RailCard, AttentionList, QuickActions, BarList],
  templateUrl: './teacher-dashboard.html',
  styleUrl: './teacher-dashboard.css',
})
export class TeacherDashboard {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  data = signal<any | null>(null);
  firstName = signal('');

  readonly kpis = computed<KpiItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const s = d.stats;
    return [
      {label: 'My classes', value: s.my_classes, icon: 'meeting_room', tone: 'success', link: '/teacher/main/students'},
      {label: 'My learners', value: s.my_students, icon: 'group', tone: 'primary', link: '/teacher/main/students'},
      {label: 'Pending reviews', value: s.pending_reviews ?? 0, icon: 'grading', tone: (s.pending_reviews ?? 0) > 0 ? 'warning' : 'secondary', link: '/teacher/academics/worksheets'},
      {label: 'Upcoming assessments', value: s.upcoming_assessments ?? 0, icon: 'quiz', tone: 'info', link: '/teacher/academics/assessments'},
      {label: 'Upcoming live', value: s.upcoming_live, icon: 'video_camera_front', tone: 'danger', link: '/teacher/academics/live-classes'},
    ];
  });

  readonly pendingSubmissions = computed<any[]>(() => this.data()?.pending_submissions ?? []);

  readonly classPerformance = computed<BarItem[]>(() =>
    (this.data()?.class_performance ?? [])
      .filter((c: any) => c.average !== null)
      .map((c: any) => ({label: c.class, value: c.average, tone: c.average >= 70 ? 'success' : c.average >= 50 ? 'warning' : 'danger'})));

  readonly attention = computed<AttentionItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const a = d.action_items;
    return [
      {label: 'Worksheets to grade', count: a.worksheets_to_grade, tone: a.worksheets_to_grade ? 'warning' : 'secondary', icon: 'assignment_turned_in', link: '/teacher/academics/worksheets'},
      {label: 'Portfolio to review', count: a.portfolio_to_review, tone: a.portfolio_to_review ? 'primary' : 'secondary', icon: 'folder_special', link: '/teacher/academics/portfolio'},
      {label: 'Interventions assigned to me', count: a.my_interventions, tone: a.my_interventions ? 'info' : 'secondary', icon: 'support', link: '/teacher/academics/interventions'},
    ];
  });

  readonly upcomingLive = computed<any[]>(() => this.data()?.upcoming ?? []);

  readonly quickActions: QuickAction[] = [
    {label: 'Open Delivery Packs', sublabel: 'Teach from a pack', icon: 'assignment', link: '/teacher/academics/lesson-content'},
    {label: 'Create Assessment', sublabel: 'Build a quiz or test', icon: 'quiz', link: '/teacher/academics/assessments'},
    {label: 'Schedule Live Class', sublabel: 'Plan a session', icon: 'video_camera_front', link: '/teacher/academics/live-classes'},
    {label: 'Review Submissions', sublabel: 'Grade worksheets', icon: 'grading', link: '/teacher/academics/worksheets'},
  ];

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'there');
    if (isPlatformBrowser(this.platformId)) {
      this.api.get<any>('/backend/dashboard/teacher').subscribe({
        next: (res) => { this.data.set(res); this.loading.set(false); },
        error: () => this.loading.set(false),
      });
    }
  }
}
