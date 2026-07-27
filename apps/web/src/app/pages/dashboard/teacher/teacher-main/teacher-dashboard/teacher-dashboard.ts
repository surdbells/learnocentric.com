import {Component, computed, inject, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {
  AttentionItem, AttentionList, KpiItem, KpiStrip, QuickAction, QuickActions, RailCard,
} from '../../../../../common/ui';

@Component({
  selector: 'app-teacher-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, DatePipe, KpiStrip, RailCard, AttentionList, QuickActions],
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
      {label: 'My subjects', value: s.my_subjects, icon: 'subject', tone: 'warning', link: '/teacher/academics/topics'},
      {label: 'My students', value: s.my_students, icon: 'group', tone: 'primary', link: '/teacher/main/students'},
      {label: 'Upcoming live', value: s.upcoming_live, icon: 'video_camera_front', tone: 'danger', link: '/teacher/academics/live-classes'},
    ];
  });

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
