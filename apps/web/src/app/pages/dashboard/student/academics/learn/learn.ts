import {Component, inject, signal} from '@angular/core';
import {Router} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

const STAGE_ICON: Record<string, string> = {lesson: 'menu_book', quiz: 'quiz', worksheet: 'assignment_turned_in', portfolio: 'folder_special'};

@Component({
  selector: 'app-learn',
  standalone: true,
  imports: [Icon, PageHeader],
  templateUrl: './learn.html',
  styleUrl: './learn.css',
})
export class Learn {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastrService);

  mode = signal<'list' | 'lesson'>('list');
  loading = signal(true);
  busy = signal(false);
  topics = signal<any[]>([]);
  lesson = signal<any | null>(null);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/learn/topics').subscribe({
      next: (res) => { this.topics.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your lessons'); },
    });
  }

  openLesson(topic: any): void {
    this.busy.set(true);
    this.api.get<any>(`/backend/learn/topics/${topic.id}`).subscribe({
      next: (res) => { this.lesson.set(res); this.mode.set('lesson'); this.busy.set(false); },
      error: () => { this.toast.error('Could not open the lesson'); this.busy.set(false); },
    });
  }

  completeLesson(): void {
    const l = this.lesson();
    if (!l) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/learn/topics/${l.id}/complete-lesson`, {}).subscribe({
      next: (res) => {
        this.toast.success('Lesson marked complete');
        this.lesson.set({...l, stages: res.stages, progress: res.progress, next_stage: res.next_stage, complete: res.complete});
        this.busy.set(false);
      },
      error: () => { this.toast.error('Could not update'); this.busy.set(false); },
    });
  }

  goToStage(stage: any): void {
    if (stage.link) this.router.navigateByUrl(stage.link);
  }

  backToList(): void {
    this.lesson.set(null);
    this.mode.set('list');
    this.load();
  }

  lessonStageDone(): boolean {
    return !!this.lesson()?.stages?.find((s: any) => s.key === 'lesson')?.done;
  }

  ctaLabel(t: any): string {
    if (t.complete) return 'Review';
    if (t.progress > 0) return 'Continue';
    return 'Start learning';
  }

  stageIcon(key: string): string { return STAGE_ICON[key] ?? 'circle'; }
  barColor(pct: number): string { return pct >= 100 ? 'success' : (pct > 0 ? 'primary' : 'secondary'); }
}
