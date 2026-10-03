import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {FileUpload, UploadedFile} from '../../../../../common/file-upload/file-upload';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {RichEditor} from '../../../../../common/rich-editor/rich-editor';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../../common/ui';

const RATING_COLOR: Record<string, string> = {emerging: 'secondary', developing: 'info', proficient: 'primary', mastery: 'success'};
/** Competency rating → number of filled stars (out of 4). */
const RATING_STARS: Record<string, number> = {emerging: 1, developing: 2, proficient: 3, mastery: 4};

@Component({
  selector: 'app-my-portfolio',
  standalone: true,
  imports: [RichText, RichEditor, Icon, PageHeader, ReactiveFormsModule, DatePipe, FileUpload, KpiStrip, TabBar],
  templateUrl: './my-portfolio.html',
  styleUrl: './my-portfolio.css',
})
export class MyPortfolio {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'list' | 'add'>('list');
  loading = signal(false);
  busy = signal(false);
  tasks = signal<any[]>([]);
  activeTab = signal<string>('all');
  /** The task the add-form is submitting evidence against. */
  activeTask = signal<any | null>(null);

  readonly stars = [1, 2, 3, 4];

  form = new FormGroup({
    topicId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    title: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    description: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    evidenceUrl: new FormControl(''),
  });

  readonly kpis = computed<KpiItem[]>(() => {
    const t = this.tasks();
    const reviewed = t.filter(x => x.status === 'reviewed');
    const mastery = reviewed.filter(x => x.competency_rating === 'mastery').length;
    return [
      {label: 'Tasks assigned', value: t.length, icon: 'assignment', tone: 'primary'},
      {label: 'To do', value: t.filter(x => x.status === 'to_do').length, icon: 'edit_square', tone: 'warning'},
      {label: 'Awaiting review', value: t.filter(x => x.status === 'submitted').length, icon: 'schedule', tone: 'info'},
      {label: 'Mastery reached', value: mastery, icon: 'workspace_premium', tone: 'success'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const t = this.tasks();
    return [
      {key: 'all', label: 'All', count: t.length},
      {key: 'to_do', label: 'To do', count: t.filter(x => x.status === 'to_do').length},
      {key: 'submitted', label: 'Submitted', count: t.filter(x => x.status === 'submitted').length},
      {key: 'reviewed', label: 'Reviewed', count: t.filter(x => x.status === 'reviewed').length},
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const tab = this.activeTab(), t = this.tasks();
    return tab === 'all' ? t : t.filter(x => x.status === tab);
  });

  constructor() {
    this.load();
  }

  onEvidenceUploaded(file: UploadedFile): void { this.form.get('evidenceUrl')!.setValue(file.url); }
  onEvidenceCleared(): void { this.form.get('evidenceUrl')!.setValue(''); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/portfolio/tasks').subscribe({
      next: (res) => { this.tasks.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your portfolio tasks'); },
    });
  }

  /** Open the evidence form for a specific task, pre-binding its topic. */
  openTask(task: any): void {
    this.form.reset();
    this.form.get('topicId')!.setValue(task.task_id);
    this.activeTask.set(task);
    this.mode.set('add');
  }

  submit(): void {
    if (this.form.invalid) { this.toast.error('A title and description are required'); return; }
    const v = this.form.value;
    this.busy.set(true);
    this.api.post<any>('/backend/assessment/portfolio', {
      topic_id: v.topicId, title: v.title, description: v.description, evidence_url: v.evidenceUrl,
    }).subscribe({
      next: () => { this.toast.success('Evidence submitted'); this.busy.set(false); this.backToList(); },
      error: (e) => { this.toast.error(e?.error?.error || 'Submit failed'); this.busy.set(false); },
    });
  }

  backToList(): void {
    this.activeTask.set(null);
    this.mode.set('list');
    this.load();
  }

  ratingColor(r: string | null): string { return r ? (RATING_COLOR[r] ?? 'secondary') : 'secondary'; }
  ratingStars(r: string | null): number { return r ? (RATING_STARS[r] ?? 0) : 0; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
  statusLabel(s: string): string {
    return s === 'to_do' ? 'To do' : s === 'submitted' ? 'Awaiting review' : s === 'reviewed' ? 'Reviewed' : this.titleCase(s);
  }
  statusTone(s: string): string {
    return s === 'reviewed' ? 'success' : s === 'submitted' ? 'info' : 'warning';
  }
}
