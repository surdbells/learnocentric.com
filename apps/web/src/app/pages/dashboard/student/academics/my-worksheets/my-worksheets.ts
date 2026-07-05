import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {FileUpload, UploadedFile} from '../../../../../common/file-upload/file-upload';
import {ApiService} from '../../../../../common/service/api.service';

@Component({
  selector: 'app-my-worksheets',
  standalone: true,
  imports: [PageHeader, FormsModule, DatePipe, FileUpload],
  templateUrl: './my-worksheets.html',
  styleUrl: './my-worksheets.css',
})
export class MyWorksheets {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'list' | 'do'>('list');
  loading = signal(false);
  busy = signal(false);
  available = signal<any[]>([]);
  current = signal<any | null>(null);
  responseText = signal('');
  attachmentUrl = signal('');

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/worksheets/available').subscribe({
      next: (res) => { this.available.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load worksheets'); },
    });
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
