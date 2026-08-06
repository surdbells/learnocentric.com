import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {Router} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Icon} from '../../../common/icon/icon';
import {RichText} from '../../../common/rich-editor/rich-text';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../common/ui';

const CATEGORY_TONE: Record<string, string> = {general: 'secondary', academics: 'primary', events: 'warning', internal: 'info', attendance: 'success', reminder: 'primary'};
const STATUS_TONE: Record<string, string> = {sent: 'success', scheduled: 'primary', draft: 'secondary'};

/**
 * Communication hub (design: Communication_SA) for staff — KPI strip, an
 * announcements log with filters, and a summary/attention/quick-actions rail.
 * Learners/parents get a clean read-only feed of what's addressed to them.
 */
@Component({
  selector: 'app-announcements',
  standalone: true,
  imports: [RichText, Icon, PageHeader, DatePipe, FormsModule, KpiStrip, TabBar],
  templateUrl: './announcements.html',
  styleUrl: './announcements.css',
})
export class Announcements {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly role = this.auth.getAuthSession()?.user?.role ?? '';
  readonly isStaff = ['school_admin', 'tutor_admin', 'teacher'].includes(this.role);
  readonly root = this.router.url.split('/')[1] || 'admin';

  loading = signal(true);
  items = signal<any[]>([]);
  stats = signal<any>({});
  activeTab = signal<string>('all');
  search = signal('');
  categoryFilter = signal('all');
  expandedId = signal<number | null>(null);

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/messaging/announcements').subscribe({
      next: (res) => { this.items.set(Array.isArray(res) ? res : []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load announcements'); },
    });
    if (this.isStaff) {
      this.api.get<any>('/backend/messaging/announcements/stats').subscribe({next: (s) => this.stats.set(s ?? {})});
    }
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.stats();
    return [
      {label: 'Announcements Sent', value: s.announcements_sent ?? 0, icon: 'campaign', tone: 'primary'},
      {label: 'Recipients Reached', value: s.recipients_reached ?? 0, icon: 'groups', tone: 'success'},
      {label: 'Direct Messages', value: s.messages_total ?? 0, icon: 'forum', tone: 'info'},
      {label: 'Drafts & Scheduled', value: (s.drafts ?? 0) + (s.scheduled ?? 0), icon: 'schedule', tone: (s.drafts || s.scheduled) ? 'warning' : 'secondary'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const n = this.items();
    const cnt = (st: string) => n.filter(x => x.status === st).length;
    return [
      {key: 'all', label: 'All', count: n.length},
      {key: 'sent', label: 'Sent', count: cnt('sent')},
      {key: 'scheduled', label: 'Scheduled', count: cnt('scheduled')},
      {key: 'draft', label: 'Drafts', count: cnt('draft')},
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), q = this.search().toLowerCase().trim(), cat = this.categoryFilter();
    return this.items().filter(a => {
      if (t !== 'all' && a.status !== t) return false;
      if (cat !== 'all' && a.category !== cat) return false;
      if (q && !(`${a.title} ${a.subject ?? ''}`.toLowerCase().includes(q))) return false;
      return true;
    });
  });

  compose(draft?: any): void {
    this.router.navigate([`/${this.root}/communication/announcements/new`], draft ? {state: {draft}} : {});
  }

  toggleExpand(a: any): void { this.expandedId.set(this.expandedId() === a.id ? null : a.id); }

  remove(a: any): void {
    if (!confirm(`Delete "${a.title}"?`)) return;
    this.api.delete(`/backend/messaging/announcements/${a.id}`).subscribe({
      next: () => { this.toast.success('Deleted'); this.load(); },
      error: () => this.toast.error('Could not delete'),
    });
  }

  audienceLabel(a: any): string {
    if (a.audience === 'class') return a.class_label || 'Selected class';
    const map: Record<string, string> = {all: 'Everyone', students: 'All Students', teachers: 'Teachers', parents: 'Parents', staff: 'Teachers & Staff'};
    return map[a.audience] ?? a.audience;
  }
  categoryTone(c: string): string { return CATEGORY_TONE[c] ?? 'secondary'; }
  statusTone(s: string): string { return STATUS_TONE[s] ?? 'secondary'; }
  priorityTone(p: string): string { return p === 'high' ? 'danger' : p === 'low' ? 'secondary' : 'warning'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
