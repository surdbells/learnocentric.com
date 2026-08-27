import {Component, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {HttpClient, HttpEventType} from '@angular/common/http';
import {ToastrService} from 'ngx-toastr';
import {Icon} from '../icon/icon';

export interface UploadedFile {
  /** Backend-served reference (/backend/files?p=…), never a raw file URL. */
  url: string;
  /** Bare Flysystem path stored server-side. */
  path?: string;
  name: string;
  size: number;
  type: string;
}

/** Server response from POST /backend/upload, path-only (no URL issued). */
interface UploadResponse { path: string; name: string; size: number; type: string; }

/**
 * Reusable file uploader with real progress. Uploads to /backend/upload
 * (Flysystem) via HttpClient reportProgress and emits the stored file's URL.
 */
@Component({
  selector: 'app-file-upload',
  standalone: true,
  imports: [Icon, ],
  templateUrl: './file-upload.html',
  styleUrl: './file-upload.css',
})
export class FileUpload {
  /** Existing value (URL) to show as already-uploaded. */
  value = input<string | null>(null);
  label = input<string>('Upload a file');
  accept = input<string>('image/*,application/pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.csv,.txt,video/*,audio/*');
  hint = input<string>('Images, documents, audio or video, up to 15 MB.');

  @Output() uploaded = new EventEmitter<UploadedFile>();
  @Output() cleared = new EventEmitter<void>();

  private readonly http = inject(HttpClient);
  private readonly toast = inject(ToastrService);

  progress = signal<number | null>(null);
  current = signal<UploadedFile | null>(null);
  fileName = signal('');
  /** Inline error message shown when an upload fails; null when there's no error. */
  error = signal<string | null>(null);

  /** The last file the user chose, kept so a failed upload can be retried as-is. */
  private pendingFile: File | null = null;

  onSelect(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    if (file.size > 15 * 1024 * 1024) {
      this.toast.error('That file is larger than 15 MB.');
      input.value = '';
      return;
    }
    this.error.set(null);
    this.fileName.set(file.name);
    this.pendingFile = file;
    this.upload(file);
    input.value = '';
  }

  private upload(file: File): void {
    const form = new FormData();
    form.append('file', file, file.name);
    this.error.set(null);
    this.progress.set(0);

    this.http.post<UploadResponse>('/backend/upload', form, {reportProgress: true, observe: 'events'}).subscribe({
      next: (event) => {
        if (event.type === HttpEventType.UploadProgress && event.total) {
          this.progress.set(Math.round((event.loaded / event.total) * 100));
        } else if (event.type === HttpEventType.Response && event.body) {
          const body = event.body;
          // Path-only contract: build the backend-served reference from the path.
          const uploaded: UploadedFile = {
            url: body.path ? '/backend/files?p=' + body.path : '',
            path: body.path,
            name: body.name,
            size: body.size,
            type: body.type,
          };
          this.current.set(uploaded);
          this.progress.set(null);
          this.pendingFile = null;
          this.uploaded.emit(uploaded);
          this.toast.success('File uploaded');
        }
      },
      error: (e) => {
        this.progress.set(null);
        this.error.set(e?.error?.error || 'Upload failed, check your connection and try again.');
      },
    });
  }

  /** Re-attempt the exact same file that just failed. */
  retry(): void {
    if (this.pendingFile) this.upload(this.pendingFile);
  }

  /** Discard the failed file and return to the empty picker. */
  chooseAnother(): void {
    this.error.set(null);
    this.pendingFile = null;
    this.fileName.set('');
  }

  remove(): void {
    this.current.set(null);
    this.fileName.set('');
    this.progress.set(null);
    this.error.set(null);
    this.pendingFile = null;
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
