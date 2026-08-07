import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Icon} from '../../../common/icon/icon';
import {RichText} from '../../../common/rich-editor/rich-text';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../common/ui';
import {LearnoModal} from '../../../components/learno-modal/learno-modal';

declare const bootstrap: any;

/**
 * Learning resources available to the institution through its assigned content
 * package (spec §8). Learners + staff get a read view; staff can also upload
 * school-owned resources (visible to the institution's learners).
 */
@Component({
  selector: 'app-resources',
  standalone: true,
  imports: [RichText, Icon, RouterLink, DatePipe, FormsModule, PageHeader, KpiStrip, TabBar, LearnoModal],
  templateUrl: './resources.html',
  styleUrl: './resources.css',
})
export class Resources {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  loadError = signal<string | null>(null);
  package = signal<any | null>(null);
  resources = signal<any[]>([]);
  activeTab = signal<string>('all');
  sortKey = signal<'recent' | 'name' | 'size'>('recent');
  viewerRoot = signal('/student');

  isStaff = signal(false);
  myUploads = signal<any[]>([]);

  // Upload form
  upTitle = signal('');
  upType = signal('document');
  upSubject = signal('');
  upDesc = signal('');
  upTags = signal('');
  upFile = signal<File | null>(null);
  upBusy = signal(false);

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
    const t = this.activeTab(), sort = this.sortKey();
    let r = t === 'all' ? [...this.resources()] : this.resources().filter(x => x.contentType === t);
    if (sort === 'name') r.sort((a, b) => (a.title || '').localeCompare(b.title || ''));
    else if (sort === 'size') r.sort((a, b) => (b.file_size || 0) - (a.file_size || 0));
    else r.sort((a, b) => (b.created_at || '').localeCompare(a.created_at || ''));
    return r;
  });

  /** Featured picks — up to 4, one per type where possible for variety. */
  readonly featured = computed<any[]>(() => {
    const r = this.resources();
    const seen = new Set<string>();
    const picks: any[] = [];
    for (const x of r) { if (!seen.has(x.contentType)) { seen.add(x.contentType); picks.push(x); } if (picks.length === 4) break; }
    for (const x of r) { if (picks.length === 4) break; if (!picks.includes(x)) picks.push(x); }
    return picks;
  });

  /** Per-subject resource counts for the rail. */
  readonly bySubject = computed<any[]>(() => {
    const map = new Map<string, number>();
    for (const x of this.resources()) {
      const s = x.subjectArea || 'General';
      map.set(s, (map.get(s) ?? 0) + 1);
    }
    return [...map.entries()].map(([subject, count]) => ({subject, count})).sort((a, b) => b.count - a.count);
  });

  /** Real total size of the resource library (sum of file sizes). */
  readonly libraryBytes = computed<number>(() => this.resources().reduce((s, x) => s + (x.file_size || 0), 0));
  readonly librarySizeLabel = computed<string>(() => this.fileSizeLabel(this.libraryBytes()));
  readonly downloadablePct = computed<number>(() => {
    const r = this.resources();
    if (!r.length) return 0;
    return Math.round(r.filter(x => this.isDownloadable(x)).length / r.length * 100);
  });

  fileSizeLabel(bytes: number): string {
    if (!bytes) return '—';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
  }

  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  constructor() {
    const role = this.auth.getAuthSession()?.user?.role;
    this.viewerRoot.set(role === 'teacher' ? '/teacher' : role === 'school_admin' ? '/admin' : role === 'tutor_admin' ? '/academy' : '/student');
    this.isStaff.set(['teacher', 'academic_lead', 'school_admin', 'tutor_admin'].includes(role ?? ''));
    this.load();
    if (this.isStaff()) this.loadUploads();
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

  loadUploads(): void {
    this.api.get<any>('/backend/content/school-resources').subscribe({
      next: (res) => this.myUploads.set(res?.data ?? []),
      error: () => {},
    });
  }

  onUploadFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.upFile.set(input.files?.[0] ?? null);
  }

  uploadResource(): void {
    if (!this.upTitle().trim()) { this.toast.error('Give the resource a title'); return; }
    if (!this.upFile()) { this.toast.error('Choose a file to upload'); return; }
    const form = new FormData();
    form.append('title', this.upTitle().trim());
    form.append('contentType', this.upType());
    form.append('subjectArea', this.upSubject().trim());
    form.append('description', this.upDesc().trim());
    form.append('tags', this.upTags().trim());
    form.append('file', this.upFile()!);
    this.upBusy.set(true);
    this.api.post<any>('/backend/content/school-resources', form).subscribe({
      next: () => {
        this.toast.success('Resource uploaded');
        this.upBusy.set(false);
        this.upTitle.set(''); this.upSubject.set(''); this.upDesc.set(''); this.upTags.set(''); this.upFile.set(null);
        this.close('upload_resource');
        this.load();
        this.loadUploads();
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Upload failed'); this.upBusy.set(false); },
    });
  }

  deleteUpload(row: any): void {
    this.api.delete<any>(`/backend/content/school-resources?id=${row.id}`, {confirm: `Delete “${row.title}”? Learners will no longer see it.`}).subscribe({
      next: () => { this.toast.success('Resource deleted'); this.load(); this.loadUploads(); },
      error: (e) => this.toast.error(e?.error?.error || 'Delete failed'),
    });
  }

  private close(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
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
