import {Component, computed, inject, PLATFORM_ID, signal} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {
  AttentionItem, AttentionList, KpiItem, KpiStrip, QuickAction, QuickActions, RailCard, StatRing,
} from '../../../../../common/ui';

@Component({
  selector: 'app-student-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, KpiStrip, RailCard, AttentionList, QuickActions, StatRing],
  templateUrl: './student-dashboard.html',
  styleUrl: './student-dashboard.css',
})
export class StudentDashboard {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  data = signal<any | null>(null);
  firstName = signal('');

  readonly lessonPct = computed<number>(() => {
    const s = this.data()?.stats;
    if (!s || !s.topics) return 0;
    return Math.round((s.lessons_viewed / s.topics) * 100);
  });

  readonly quizAvg = computed<number>(() => Number(this.data()?.stats?.quiz_average ?? 0));

  readonly kpis = computed<KpiItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const s = d.stats;
    return [
      {label: 'Lessons viewed', value: `${s.lessons_viewed}/${s.topics}`, icon: 'menu_book', tone: 'primary', link: '/student/academics/learn'},
      {label: 'Quizzes taken', value: s.quizzes_taken, icon: 'quiz', tone: 'info', link: '/student/academics/assessments'},
      {label: 'Quiz average', value: this.pct(s.quiz_average), icon: 'workspace_premium', tone: 'success'},
      {label: 'Upcoming live', value: d.action_items.upcoming_live, icon: 'video_camera_front', tone: 'danger', link: '/student/academics/live-classes'},
    ];
  });

  readonly dueTasks = computed<AttentionItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const a = d.action_items;
    return [
      {label: 'Pending worksheets', count: a.pending_worksheets, tone: a.pending_worksheets ? 'warning' : 'secondary', icon: 'assignment', link: '/student/academics/worksheets'},
      {label: 'Unread feedback', count: a.unread_feedback, tone: a.unread_feedback ? 'primary' : 'secondary', icon: 'chat', link: '/student/academics/feedback'},
      {label: 'New notifications', count: a.unread_notifications, tone: a.unread_notifications ? 'info' : 'secondary', icon: 'notifications', link: '/student/communication/announcements'},
    ];
  });

  readonly quickActions: QuickAction[] = [
    {label: 'Continue Learning', sublabel: 'Resume your lessons', icon: 'play_circle', link: '/student/academics/learn'},
    {label: 'Take a Quiz', sublabel: 'Practise & assess', icon: 'quiz', link: '/student/academics/assessments'},
    {label: 'My Worksheets', sublabel: 'Complete & submit', icon: 'assignment', link: '/student/academics/worksheets'},
    {label: 'Live Classes', sublabel: 'Join a session', icon: 'video_camera_front', link: '/student/academics/live-classes'},
  ];

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'there');
    if (isPlatformBrowser(this.platformId)) {
      this.api.get<any>('/backend/dashboard/student').subscribe({
        next: (res) => { this.data.set(res); this.loading.set(false); },
        error: () => this.loading.set(false),
      });
    }
  }

  scoreColor(p: number | null): string {
    if (p === null || p === undefined) return 'secondary';
    if (p >= 70) return 'success';
    if (p >= 50) return 'warning';
    return 'danger';
  }
  pct(v: number | null): string { return v === null || v === undefined ? '—' : v + '%'; }
}
