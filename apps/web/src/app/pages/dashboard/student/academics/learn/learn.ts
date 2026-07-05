import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {Router} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {MediaEmbed} from '../../../../../common/media-embed/media-embed';
import {FormsModule} from '@angular/forms';

const STAGE_ICON: Record<string, string> = {lesson: 'menu_book', quiz: 'quiz', worksheet: 'assignment_turned_in', portfolio: 'folder_special'};

@Component({
  selector: 'app-learn',
  standalone: true,
  imports: [Icon, PageHeader, MediaEmbed, FormsModule, DatePipe],
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

  // Note-taking while learning
  noteBody = signal('');
  noteSaving = signal(false);
  noteSavedAt = signal<string | null>(null);

  constructor() {
    this.load();
  }

  /** Combined media: the media[] list, falling back to a lone video_url. */
  lessonMedia(): { url: string; name?: string }[] {
    const l = this.lesson()?.lesson;
    if (!l) return [];
    if (Array.isArray(l.media) && l.media.length) return l.media;
    return l.video_url ? [{url: l.video_url, name: 'Lesson video'}] : [];
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
    this.noteBody.set('');
    this.noteSavedAt.set(null);
    this.api.get<any>(`/backend/learn/topics/${topic.id}`).subscribe({
      next: (res) => { this.lesson.set(res); this.mode.set('lesson'); this.busy.set(false); this.loadNote(topic.id); },
      error: () => { this.toast.error('Could not open the lesson'); this.busy.set(false); },
    });
  }

  private loadNote(topicId: number): void {
    this.api.get<any>(`/backend/learn/topics/${topicId}/note`).subscribe({
      next: (res) => { this.noteBody.set(res?.body ?? ''); this.noteSavedAt.set(res?.updated_at ?? null); },
      error: () => {},
    });
  }

  saveNote(): void {
    const l = this.lesson();
    if (!l) return;
    this.noteSaving.set(true);
    this.api.put<any>(`/backend/learn/topics/${l.id}/note`, {body: this.noteBody()}).subscribe({
      next: (res) => { this.noteSavedAt.set(res?.updated_at ?? new Date().toISOString()); this.noteSaving.set(false); this.toast.success('Note saved'); },
      error: () => { this.toast.error('Could not save note'); this.noteSaving.set(false); },
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
