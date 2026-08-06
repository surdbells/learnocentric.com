import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {FileUpload, UploadedFile} from '../../../../../common/file-upload/file-upload';
import {ApiService} from '../../../../../common/service/api.service';
import {RichEditor} from '../../../../../common/rich-editor/rich-editor';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../../common/ui';
import {WorksheetSolver} from './worksheet-solver/worksheet-solver';

@Component({
  selector: 'app-my-worksheets',
  standalone: true,
  imports: [RichText, RichEditor, PageHeader, FormsModule, DatePipe, FileUpload, Icon, KpiStrip, TabBar, WorksheetSolver],
  templateUrl: './my-worksheets.html',
  styleUrl: './my-worksheets.css',
})
export class MyWorksheets {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'list' | 'do'>('list');
  loading = signal(false);
  loadError = signal<string | null>(null);
  busy = signal(false);
  available = signal<any[]>([]);
  current = signal<any | null>(null);
  responseText = signal('');
  attachmentUrl = signal('');
  activeTab = signal<string>('all');

  /** Bucket a worksheet by its submission state. */
  wKey(w: any): string {
    return w.submission?.status === 'graded' ? 'graded'
      : w.submission?.status === 'submitted' ? 'submitted' : 'not_started';
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const a = this.available();
    const graded = a.filter(w => w.submission?.status === 'graded');
    const submitted = a.filter(w => w.submission?.status === 'submitted');
    const scored = graded.filter(w => w.submission?.score != null && w.total_marks);
    const avg = scored.length
      ? Math.round(scored.reduce((s, w) => s + (w.submission.score / w.total_marks) * 100, 0) / scored.length)
      : null;
    return [
      {label: 'Total worksheets', value: a.length, icon: 'assignment', tone: 'primary'},
      {label: 'Completed', value: graded.length, icon: 'assignment_turned_in', tone: 'success'},
      {label: 'Awaiting grade', value: submitted.length, icon: 'schedule', tone: submitted.length ? 'info' : 'secondary'},
      {label: 'Average score', value: avg === null ? '—' : avg + '%', icon: 'workspace_premium', tone: 'warning'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const a = this.available();
    const cnt = (k: string) => a.filter(w => this.wKey(w) === k).length;
    return [
      {key: 'all', label: 'All', count: a.length},
      {key: 'not_started', label: 'Not Started', count: cnt('not_started')},
      {key: 'submitted', label: 'Submitted', count: cnt('submitted')},
      {key: 'graded', label: 'Completed', count: cnt('graded')},
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), a = this.available();
    return t === 'all' ? a : a.filter(w => this.wKey(w) === t);
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    this.api.get<any>('/backend/assessment/worksheets/available').subscribe({
      next: (res) => { this.available.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.loadError.set('We couldn\'t load your worksheets. Please check your connection and try again.'); },
    });
  }

  /** Filename for a download link, derived from a served file reference (/backend/files?p=<path>). */
  downloadName(url: string | undefined | null): string {
    const raw = url || '';
    // Prefer the stored path carried in the ?p= query param; fall back to the URL itself.
    const m = raw.match(/[?&]p=([^&]+)/);
    const path = m ? decodeURIComponent(m[1]) : raw.split('?')[0];
    return path.split('#')[0].split('/').pop() || 'download';
  }

  openDo(w: any): void {
    this.current.set(w);
    this.responseText.set(w.submission?.response_text ?? '');
    this.attachmentUrl.set(w.submission?.attachment_url ?? '');
    this.mode.set('do');
  }

  onFileUploaded(file: UploadedFile): void { this.attachmentUrl.set(file.url); }
  onFileCleared(): void { this.attachmentUrl.set(''); }

  submit(): void {
    const w = this.current();
    if (!w) return;
    if (!this.responseText().trim() && !this.attachmentUrl()) { this.toast.error('Write your answers or attach a file before submitting'); return; }
    this.busy.set(true);
    this.api.post<any>(`/backend/assessment/worksheets/${w.id}/submit`, {response_text: this.responseText(), attachment_url: this.attachmentUrl()}).subscribe({
      next: () => { this.toast.success('Worksheet submitted'); this.busy.set(false); this.backToList(); },
      error: (e) => { this.toast.error(e?.error?.error || 'Submit failed'); this.busy.set(false); },
    });
  }

  backToList(): void {
    this.current.set(null);
    this.mode.set('list');
    this.load();
  }

  statusColor(s: string | undefined): string {
    if (s === 'graded') return 'success';
    if (s === 'submitted') return 'info';
    return 'secondary';
  }

  isGraded(w: any): boolean { return w.submission?.status === 'graded'; }
}
