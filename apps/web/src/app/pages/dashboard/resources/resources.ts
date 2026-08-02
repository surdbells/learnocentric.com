import {Component, computed, inject, signal} from '@angular/core';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';
import {Icon} from '../../../common/icon/icon';
import {RichText} from '../../../common/rich-editor/rich-text';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../common/ui';

/**
 * Learning resources available to the institution through its assigned content
 * package (spec §8). Read-only view for staff and learners.
 */
@Component({
  selector: 'app-resources',
  standalone: true,
  imports: [RichText, Icon, PageHeader, KpiStrip, TabBar],
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
  activeTab = signal<string>('all');

  readonly kpis = computed<KpiItem[]>(() => {
    const r = this.resources();
    const byType = (t: string) => r.filter(x => x.contentType === t).length;
    return [
      {label: 'All resources', value: r.length, icon: 'library_books', tone: 'primary'},
      {label: 'Videos', value: byType('video'), icon: 'smart_display', tone: 'danger'},
      {label: 'Documents', value: byType('document'), icon: 'description', tone: 'info'},
      {label: 'Downloadable', value: r.filter(x => this.isDownloadable(x)).length, icon: 'download', tone: 'success'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const r = this.resources();
    const types = [...new Set(r.map(x => x.contentType).filter(Boolean))] as string[];
    return [
      {key: 'all', label: 'All', count: r.length},
      ...types.map(t => ({key: t, label: this.titleCase(t), count: r.filter(x => x.contentType === t).length})),
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), r = this.resources();
    return t === 'all' ? r : r.filter(x => x.contentType === t);
  });

  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

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
