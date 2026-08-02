import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {Router} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {MediaEmbed} from '../../../../../common/media-embed/media-embed';
import {RichEditor} from '../../../../../common/rich-editor/rich-editor';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {FormsModule} from '@angular/forms';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../../common/ui';

const STAGE_ICON: Record<string, string> = {lesson: 'menu_book', quiz: 'quiz', worksheet: 'assignment_turned_in', portfolio: 'folder_special'};

@Component({
  selector: 'app-learn',
  standalone: true,
  imports: [Icon, PageHeader, MediaEmbed, RichEditor, RichText, FormsModule, DatePipe, KpiStrip, TabBar],
  templateUrl: './learn.html',
  styleUrl: './learn.css',
})
export class Learn {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastrService);

  mode = signal<'list' | 'lesson'>('list');
  loading = signal(true);
  loadError = signal<string | null>(null);
  busy = signal(false);
  topics = signal<any[]>([]);
  lesson = signal<any | null>(null);

  // Note-taking while learning
  noteBody = signal('');
  noteSaving = signal(false);
  noteSavedAt = signal<string | null>(null);

  // Lesson content tabs
  activeTab = signal<string>('lesson');
  // Which media item is playing in the Media tab
  activeMediaIndex = signal(0);

  // List-view status filter (distinct from the in-lesson content tabs above)
  listTab = signal<string>('all');

  /** Bucket a topic by its learning progress. */
  lessonKey(t: any): string { return t.complete ? 'completed' : (t.progress > 0 ? 'in_progress' : 'not_started'); }

  readonly lessonKpis = computed<KpiItem[]>(() => {
    const t = this.topics();
    return [
      {label: 'Total lessons', value: t.length, icon: 'menu_book', tone: 'primary'},
      {label: 'Completed', value: t.filter(x => x.complete).length, icon: 'check_circle', tone: 'success'},
      {label: 'In progress', value: t.filter(x => !x.complete && x.progress > 0).length, icon: 'play_circle', tone: 'info'},
      {label: 'Not started', value: t.filter(x => !x.progress).length, icon: 'schedule', tone: 'warning'},
    ];
  });

  readonly listTabs = computed<TabItem[]>(() => {
    const t = this.topics();
    const cnt = (k: string) => t.filter(x => this.lessonKey(x) === k).length;
    return [
      {key: 'all', label: 'All', count: t.length},
      {key: 'in_progress', label: 'In Progress', count: cnt('in_progress')},
      {key: 'completed', label: 'Completed', count: cnt('completed')},
      {key: 'not_started', label: 'Not Started', count: cnt('not_started')},
    ];
  });

  readonly listFiltered = computed<any[]>(() => {
    const k = this.listTab(), t = this.topics();
    return k === 'all' ? t : t.filter(x => this.lessonKey(x) === k);
  });

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

  selectMedia(i: number): void { this.activeMediaIndex.set(i); }
  activeMedia(): { url: string; name?: string } {
    const m = this.lessonMedia();
    return m[this.activeMediaIndex()] ?? m[0] ?? {url: '', name: ''};
  }

  /** Icon + label for a media item, from its URL. */
  private mediaKind(url: string): 'video' | 'audio' | 'pdf' | 'image' | 'link' {
    const u = (url || '').toLowerCase();
    if (/youtube|youtu\.be|vimeo/.test(u)) return 'video';
    const ext = u.split('?')[0].split('#')[0].split('.').pop() || '';
    if (['mp4', 'webm', 'mov', 'm4v', 'ogv'].includes(ext)) return 'video';
    if (['mp3', 'wav', 'm4a', 'aac', 'oga'].includes(ext)) return 'audio';
    if (ext === 'pdf') return 'pdf';
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif'].includes(ext)) return 'image';
    return 'link';
  }
  mediaIcon(url: string): string {
    return {video: 'play_circle', audio: 'audiotrack', pdf: 'description', image: 'image', link: 'folder'}[this.mediaKind(url)];
  }
  mediaTypeLabel(url: string): string {
    return {video: 'Video', audio: 'Audio', pdf: 'PDF', image: 'Image', link: 'Resource'}[this.mediaKind(url)];
  }

  /** The tabs to show for the current lesson, based on what content exists. */
  readonly tabs = computed<{ key: string; label: string; icon: string }[]>(() => {
    const l = this.lesson();
    if (!l) return [];
    const tabs = [{key: 'lesson', label: 'Lesson', icon: 'menu_book'}];
    if (this.lessonMedia().length) tabs.push({key: 'media', label: 'Media', icon: 'play_circle'});
    if (l.lesson?.worked_examples) tabs.push({key: 'examples', label: 'Worked examples', icon: 'assignment'});
    tabs.push({key: 'notes', label: 'My notes', icon: 'edit_square'});
    return tabs;
  });

  setTab(key: string): void { this.activeTab.set(key); }

  load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    this.api.get<any>('/backend/learn/topics').subscribe({
      next: (res) => { this.topics.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.loadError.set('We couldn\'t load your lessons. Please check your connection and try again.'); },
    });
  }

  openLesson(topic: any): void {
    this.busy.set(true);
    this.noteBody.set('');
    this.noteSavedAt.set(null);
    this.activeTab.set('lesson');
    this.activeMediaIndex.set(0);
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
