import {Component, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {HttpClient, HttpEventType} from '@angular/common/http';
import {ToastrService} from 'ngx-toastr';

export interface UploadedFile {
  url: string;
  name: string;
  size: number;
  type: string;
}

/**
 * Reusable file uploader with real progress. Uploads to /backend/upload
 * (Flysystem) via HttpClient reportProgress and emits the stored file's URL.
 */
@Component({
  selector: 'app-file-upload',
  standalone: true,
  imports: [],
  templateUrl: './file-upload.html',
  styleUrl: './file-upload.css',
})
export class FileUpload {
  /** Existing value (URL) to show as already-uploaded. */
  value = input<string | null>(null);
  label = input<string>('Upload a file');
  accept = input<string>('image/*,application/pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.csv,.txt,video/*,audio/*');
  hint = input<string>('Images, documents, audio or video — up to 15 MB.');

  @Output() uploaded = new EventEmitter<UploadedFile>();
  @Output() cleared = new EventEmitter<void>();

  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastrService);

  progress = signal<number | null>(null);
  current = signal<UploadedFile | null>(null);
  fileName = signal('');

  onSelect(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    if (file.size > 15 * 1024 * 1024) {
      this.toast.error('That file is larger than 15 MB.');
      input.value = '';
      return;
    }
    this.fileName.set(file.name);
    this.upload(file);
    input.value = '';
  }

  private upload(file: File): void {
    const form = new FormData();
    form.append('file', file, file.name);
    this.progress.set(0);

    this.http.post<UploadedFile>('/backend/upload', form, {reportProgress: true, observe: 'events'}).subscribe({
      next: (event) => {
        if (event.type === HttpEventType.UploadProgress && event.total) {
          this.progress.set(Math.round((event.loaded / event.total) * 100));
        } else if (event.type === HttpEventType.Response && event.body) {
          this.current.set(event.body);
          this.progress.set(null);
          this.uploaded.emit(event.body);
          this.toast.success('File uploaded');
        }
      },
      error: (e) => {
        this.progress.set(null);
        this.toast.error(e?.error?.error || 'Upload failed');
      },
    });
  }

  remove(): void {
    this.current.set(null);
    this.fileName.set('');
    this.progress.set(null);
    this.cleared.emit();
  }

  displayUrl(): string | null {
    return this.current()?.url ?? this.value();
  }

  displayName(): string {
    if (this.current()) return this.current()!.name;
    const v = this.value();
    return v ? v.split('/').pop() ?? 'Attached file' : '';
  }

  isImage(): boolean {
    const u = this.displayUrl() ?? '';
    return /\.(png|jpe?g|gif|webp)$/i.test(u);
  }
}
