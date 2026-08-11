import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ActivatedRoute, Router, RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {StatRing, TabBar, TabItem, StatusBadge, Tone} from '../../../../../common/ui';

/**
 * Learner Subject Hub — one subject's whole world in a single place: lessons
 * (topic journey), worksheets, quizzes, live classes, resources and feedback,
 * all scoped to the signed-in learner. Backed by GET /learn/subjects/{id}.
 */
@Component({
  selector: 'app-subject-hub',
  standalone: true,
  imports: [Icon, RouterLink, SkeletonLoader, StatRing, TabBar, StatusBadge, DatePipe],
  templateUrl: './subject-hub.html',
  styleUrl: './subject-hub.css',
})
export class SubjectHub {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  data = signal<any | null>(null);
  tab = signal<string>('lessons');

  /** Where each learner action lives today (the hub is the jumping-off point). */
  readonly links = {
    learn: '/student/academics/learn',
    worksheets: '/student/academics/worksheets',
    assessments: '/student/academics/assessments',
    live: '/student/academics/live-classes',
    resources: '/student/academics/resources',
    feedback: '/student/academics/feedback',
  };

  readonly tabs = computed<TabItem[]>(() => {
    const d = this.data();
    return [
      {key: 'lessons', label: 'Lessons', count: d?.lessons?.length ?? 0},
      {key: 'worksheets', label: 'Worksheets', count: d?.worksheets?.length ?? 0},
      {key: 'quizzes', label: 'Quizzes', count: d?.assessments?.length ?? 0},
      {key: 'live', label: 'Live Classes', count: d?.live_classes?.length ?? 0},
      {key: 'resources', label: 'Resources', count: d?.resources?.length ?? 0},
      {key: 'feedback', label: 'Feedback', count: d?.feedback?.length ?? 0},
    ];
  });

  /** Worksheet status → badge tone. */
  readonly worksheetTones: Record<string, Tone> = {
    graded: 'success', submitted: 'info', not_started: 'secondary',
  };
  /** Live-class status → badge tone. */
  readonly liveTones: Record<string, Tone> = {
    live: 'danger', scheduled: 'primary', ended: 'secondary', cancelled: 'secondary',
  };

  /** A live class the learner can still join/attend (vs. a past/cancelled one). */
  liveJoinable(status: string): boolean {
    return status === 'live' || status === 'scheduled';
  }

  constructor() {
    this.route.paramMap.subscribe(pm => this.load(pm.get('id')));
  }

  load(id: string | null): void {
    if (!id) return;
    this.loading.set(true);
    this.api.get<any>(`/backend/learn/subjects/${id}`).subscribe({
      next: (res) => { this.data.set(res); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load this subject'); },
    });
  }

  progressTone(p: number): 'success' | 'warning' | 'danger' {
    if (p >= 75) return 'success';
    if (p >= 40) return 'warning';
    return 'danger';
  }

  /** Resolve a media resource to something openable (backend-served path or embed link). */
  resourceHref(r: any): string {
    const raw = r?.url || r?.path || '';
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw)) return raw;              // external (e.g. YouTube/Vimeo)
    return '/backend/files?p=' + raw.replace(/^\/+/, '');   // path-only stored file
  }

  resourceIcon(type: string): string {
    switch ((type || '').toLowerCase()) {
      case 'video': return 'smart_display';
      case 'audio': return 'headphones';
      case 'pdf': case 'document': return 'description';
      case 'image': return 'image';
      default: return 'attachment';
    }
  }
}
