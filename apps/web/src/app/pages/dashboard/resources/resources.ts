import {Component, inject, signal} from '@angular/core';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';
import {Icon} from '../../../common/icon/icon';
import {RichText} from '../../../common/rich-editor/rich-text';

/**
 * Learning resources available to the institution through its assigned content
 * package (spec §8). Read-only view for staff and learners.
 */
@Component({
  selector: 'app-resources',
  standalone: true,
  imports: [RichText, Icon, PageHeader],
  templateUrl: './resources.html',
  styleUrl: './resources.css',
})
export class Resources {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  loadError = signal<string | null>(null);
  package = signal<any | null>(null);
  resources = signal<any[]>([]);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    this.api.get<any>('/backend/content/my-resources').subscribe({
      next: (res) => {
        this.package.set(res?.package ?? null);
        this.resources.set(res?.resources ?? []);
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.loadError.set('We couldn\'t load your resources. Please check your connection and try again.'); },
    });
  }

  /** A downloadable resource has a real file, isn't a stream, and isn't licence-locked. */
  isDownloadable(r: any): boolean {
    const u = (r?.file_url || '').toLowerCase();
    if (!u || r?.downloadable === false) return false;
    if (/youtube|youtu\.be|vimeo/.test(u)) return false;
    return /\.(pdf|docx?|pptx?|xlsx?|csv|txt|rtf|odt|mp4|webm|mov|m4v|ogv|mp3|wav|m4a|aac|oga|png|jpe?g|gif|webp|svg|avif|zip)$/i.test(u.split('?')[0].split('#')[0]);
  }

  downloadName(r: any): string {
    const u = (r?.file_url || '').split('?')[0].split('#')[0];
    return u.split('/').pop() || 'download';
  }

  typeIcon(type: string): string {
    return ({video: 'smart_display', document: 'description', assignment: 'assignment', quiz: 'quiz', interactive: 'touch_app'} as Record<string, string>)[type] ?? 'folder';
  }
  typeColor(type: string): string {
    return ({video: 'danger', document: 'primary', assignment: 'warning', quiz: 'info', interactive: 'success'} as Record<string, string>)[type] ?? 'secondary';
  }
}
