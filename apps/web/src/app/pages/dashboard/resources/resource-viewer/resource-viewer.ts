import {Component, computed, inject, signal, PLATFORM_ID} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {DomSanitizer, SafeResourceUrl} from '@angular/platform-browser';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {RichText} from '../../../../common/rich-editor/rich-text';

@Component({
  selector: 'app-resource-viewer',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, FormsModule, RichText],
  templateUrl: './resource-viewer.html',
  styleUrl: './resource-viewer.css',
})
export class ResourceViewer {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly sanitizer = inject(DomSanitizer);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  resources = signal<any[]>([]);
  selectedId = signal<number | null>(null);
  notes = signal<string>('');
  done = signal<boolean>(false);

  readonly current = computed<any>(() => this.resources().find(r => r.id === this.selectedId()) ?? null);

  /** How to render the resource in-app, from its URL/type. */
  readonly viewMode = computed<'youtube' | 'vimeo' | 'video' | 'pdf' | 'image' | 'audio' | 'link' | 'none'>(() => {
    const r = this.current();
    if (!r) return 'none';
    const u = (r.file_url || '').toLowerCase().split('?')[0].split('#')[0];
    if (!r.file_url) return 'none';
    if (/youtube\.com|youtu\.be/.test(r.file_url)) return 'youtube';
    if (/vimeo\.com/.test(r.file_url)) return 'vimeo';
    if (/\.(mp4|webm|mov|m4v|ogv)$/.test(u)) return 'video';
    if (/\.pdf$/.test(u)) return 'pdf';
    if (/\.(png|jpe?g|gif|webp|svg|avif)$/.test(u)) return 'image';
    if (/\.(mp3|wav|m4a|aac|oga)$/.test(u)) return 'audio';
    return 'link';
  });

  readonly embedUrl = computed<SafeResourceUrl | null>(() => {
    const r = this.current();
    if (!r?.file_url) return null;
    const mode = this.viewMode();
    let url = r.file_url as string;
    if (mode === 'youtube') {
      const id = this.youtubeId(url);
      if (!id) return null;
      url = `https://www.youtube.com/embed/${id}`;
    } else if (mode === 'vimeo') {
      const id = (url.match(/vimeo\.com\/(?:video\/)?(\d+)/) || [])[1];
      if (!id) return null;
      url = `https://player.vimeo.com/video/${id}`;
    } else if (mode !== 'pdf') {
      return null;
    }
    return this.sanitizer.bypassSecurityTrustResourceUrl(url);
  });

  constructor() {
    this.api.get<any>('/backend/content/my-resources').subscribe({
      next: (res) => { this.resources.set(res?.resources ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your resources'); },
    });
  }

  onSelect(): void {
    const id = this.selectedId();
    if (!id || !isPlatformBrowser(this.platformId)) { this.done.set(false); this.notes.set(''); return; }
    this.done.set(localStorage.getItem(`resource_done_${id}`) === '1');
    this.notes.set(localStorage.getItem(`resource_notes_${id}`) ?? '');
  }

  toggleDone(): void {
    const id = this.selectedId();
    if (!id || !isPlatformBrowser(this.platformId)) return;
    const next = !this.done();
    this.done.set(next);
    if (next) localStorage.setItem(`resource_done_${id}`, '1');
    else localStorage.removeItem(`resource_done_${id}`);
    this.toast.success(next ? 'Marked as complete' : 'Marked as not complete');
  }

  saveNotes(): void {
    const id = this.selectedId();
    if (!id || !isPlatformBrowser(this.platformId)) return;
    localStorage.setItem(`resource_notes_${id}`, this.notes());
    this.toast.success('Notes saved');
  }

  private youtubeId(url: string): string | null {
    const m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([\w-]{11})/);
    return m ? m[1] : null;
  }

  fileSizeLabel(bytes: number | null): string {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  }

  isDownloadable(r: any): boolean {
    const u = (r?.file_url || '').toLowerCase();
    if (!u || r?.downloadable === false) return false;
    if (/youtube|youtu\.be|vimeo/.test(u)) return false;
    return /\.(pdf|docx?|pptx?|xlsx?|csv|txt|rtf|odt|mp4|webm|mov|m4v|ogv|mp3|wav|m4a|aac|oga|png|jpe?g|gif|webp|svg|avif|zip)$/i.test(u.split('?')[0].split('#')[0]);
  }

  typeIcon(type: string): string {
    return ({video: 'smart_display', document: 'description', assignment: 'assignment', quiz: 'quiz', interactive: 'touch_app'} as Record<string, string>)[type] ?? 'folder';
  }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
