import {Component, computed, inject, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {StatRing} from '../../../../../common/ui';

interface ProgressTile { label: string; value: string; sub: string; pct: number; icon: string; tone: string; }

@Component({
  selector: 'app-student-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, DatePipe, StatRing],
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

  readonly continueLearning = computed<any>(() => this.data()?.continue_learning ?? null);
  readonly latestQuiz = computed<any>(() => this.data()?.latest_quiz ?? null);
  readonly mastery = computed<string>(() => this.data()?.mastery ?? '-');
  readonly weakAreas = computed<string[]>(() => this.data()?.weak_areas ?? []);
  readonly dueTasks = computed<any[]>(() => this.data()?.due_tasks ?? []);
  readonly latestFeedback = computed<any>(() => this.data()?.latest_feedback ?? null);
  readonly recentSubjects = computed<any[]>(() => this.data()?.recent_subjects ?? []);
  readonly upcomingLive = computed<any[]>(() => this.data()?.upcoming ?? []);
  readonly portfolio = computed<any>(() => this.data()?.progress?.portfolio ?? null);
  readonly classLabel = computed<string | null>(() => this.data()?.class_label ?? null);
  // Common mistake = the tutor's flagged error, else the top weak area.
  readonly commonMistake = computed<string | null>(() => this.latestFeedback()?.common_error ?? this.weakAreas()[0] ?? null);
  readonly nextAction = computed<string | null>(() => this.latestFeedback()?.next_step ?? null);
  // Portfolio task status derived from portfolio progress (no per-task due date modelled).
  readonly portfolioStatus = computed(() => {
    const p = this.portfolio();
    if (!p || p.total === 0) return null;
    return {pending: p.done < p.total, done: p.done, total: p.total};
  });

  readonly progressTiles = computed<ProgressTile[]>(() => {
    const p = this.data()?.progress;
    if (!p) return [];
    const q = p.quiz_average;
    return [
      {label: 'Lessons completed', value: `${p.lessons.done} / ${p.lessons.total}`, sub: p.lessons.pct + '%', pct: p.lessons.pct, icon: 'menu_book', tone: 'primary'},
      {label: 'Quiz average', value: q === null ? '-' : q + '%', sub: this.mastery(), pct: q ?? 0, icon: 'workspace_premium', tone: this.tone(q)},
      {label: 'Worksheet completion', value: p.worksheet.pct + '%', sub: `${p.worksheet.done} / ${p.worksheet.total}`, pct: p.worksheet.pct, icon: 'assignment_turned_in', tone: 'success'},
      {label: 'Portfolio completion', value: p.portfolio.pct + '%', sub: `${p.portfolio.done} / ${p.portfolio.total}`, pct: p.portfolio.pct, icon: 'folder_special', tone: 'warning'},
    ];
  });

  readonly masteryTone = computed<string>(() => this.tone(this.data()?.progress?.quiz_average));

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'there');
    if (isPlatformBrowser(this.platformId)) {
      this.api.get<any>('/backend/dashboard/student').subscribe({
        next: (res) => { this.data.set(res); this.loading.set(false); },
        error: () => this.loading.set(false),
      });
    }
  }

  tone(p: number | null | undefined): string {
    if (p === null || p === undefined) return 'secondary';
    if (p >= 70) return 'success';
    if (p >= 50) return 'warning';
    return 'danger';
  }
  pct(v: number | null): string { return v === null || v === undefined ? '-' : v + '%'; }
}
