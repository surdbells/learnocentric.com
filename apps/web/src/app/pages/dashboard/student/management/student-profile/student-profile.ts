import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {RouterLink} from '@angular/router';
import {forkJoin} from 'rxjs';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {ProfileForm} from '../../../../../components/forms/profile-form/profile-form';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';

/**
 * Learner "My Profile" overview (design: Profile_LD), profile card, a learning
 * snapshot, enrolled subjects with progress, and derived achievements, plus
 * links to Settings for preferences and account security. Backed by
 * /learn/profile (real snapshot + threshold-derived achievements) and
 * /learn/subjects. Editing opens the existing ProfileForm.
 * Not modelled → omitted, not fabricated: time-spent, learning streak, goals.
 */
@Component({
  selector: 'app-student-profile',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, RouterLink, ProfileForm],
  templateUrl: './student-profile.html',
  styleUrl: './student-profile.css',
})
export class StudentProfile {
  private api = inject(ApiService);
  private auth = inject(AuthService);

  loading = signal(true);
  profile = signal<any>(null);
  subjects = signal<any[]>([]);
  editing = signal(false);

  readonly user = signal(this.auth.getAuthSession()?.user ?? null);
  readonly fullName = computed(() => {
    const u = this.user() as any;
    return u ? `${u.firstName ?? ''} ${u.lastName ?? ''}`.trim() : 'Learner';
  });
  readonly email = computed(() => (this.user() as any)?.['email'] ?? '');
  readonly phone = computed(() => (this.user() as any)?.['phone'] ?? '');

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    forkJoin({
      profile: this.api.get<any>('/backend/learn/profile'),
      subjects: this.api.get<any>('/backend/learn/subjects'),
    }).subscribe({
      next: ({profile, subjects}) => {
        this.profile.set(profile ?? {});
        this.subjects.set((subjects?.data ?? []).slice(0, 5));
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  readonly snapshot = computed(() => {
    const s = this.profile()?.snapshot ?? {};
    return [
      {label: 'Lessons Completed', value: s.lessons_completed ?? 0, icon: 'auto_stories', tone: 'primary'},
      {label: 'Average Score', value: s.average_score == null ? '-' : s.average_score + '%', icon: 'fact_check', tone: 'success'},
      {label: 'Quizzes Taken', value: s.quizzes_taken ?? 0, icon: 'quiz', tone: 'info'},
      {label: 'Worksheets Done', value: s.worksheets_done ?? 0, icon: 'task_alt', tone: 'warning'},
    ];
  });

  readonly achievements = computed<any[]>(() => this.profile()?.achievements ?? []);
  readonly earnedCount = computed(() => this.achievements().filter(a => a.earned).length);

  progressTone(p: number): string { return p >= 75 ? 'success' : p >= 40 ? 'warning' : 'danger'; }

  onEdited(): void {
    this.editing.set(false);
    // refresh the cached auth user (name/photo may have changed)
    this.user.set(this.auth.getAuthSession()?.user ?? null);
  }
}
