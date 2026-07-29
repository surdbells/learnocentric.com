import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../../common/ui';

const TYPE_COLOR: Record<string, string> = {praise: 'success', correction: 'warning', reteach: 'info', general: 'secondary'};
const TYPE_ICON: Record<string, string> = {praise: 'celebration', correction: 'edit_note', reteach: 'menu_book', general: 'chat'};

@Component({
  selector: 'app-my-feedback',
  standalone: true,
  imports: [RichText, Icon, PageHeader, DatePipe, KpiStrip, TabBar],
  templateUrl: './my-feedback.html',
  styleUrl: './my-feedback.css',
})
export class MyFeedback {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  notes = signal<any[]>([]);
  unread = signal(0);
  activeTab = signal<string>('all');

  readonly kpis = computed<KpiItem[]>(() => {
    const n = this.notes();
    const reviewed = n.filter(x => x.acknowledged).length;
    return [
      {label: 'Total feedback', value: n.length, icon: 'forum', tone: 'primary'},
      {label: 'Needs action', value: this.unread(), icon: 'notifications', tone: this.unread() ? 'warning' : 'secondary'},
      {label: 'Reviewed', value: reviewed, icon: 'done_all', tone: 'success'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const n = this.notes();
    const byType = (t: string) => n.filter(x => x.type === t).length;
    return [
      {key: 'all', label: 'All', count: n.length},
      {key: 'needs_action', label: 'Needs Action', count: this.unread()},
      {key: 'praise', label: 'Praise', count: byType('praise')},
      {key: 'correction', label: 'Correction', count: byType('correction')},
      {key: 'reteach', label: 'Reteach', count: byType('reteach')},
      {key: 'general', label: 'General', count: byType('general')},
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), n = this.notes();
    if (t === 'all') return n;
    if (t === 'needs_action') return n.filter(x => !x.acknowledged);
    return n.filter(x => x.type === t);
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/feedback/mine').subscribe({
      next: (res) => { this.notes.set(res?.data ?? []); this.unread.set(res?.meta?.unread ?? 0); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your feedback'); },
    });
  }

  acknowledge(note: any): void {
    this.api.post<any>(`/backend/assessment/feedback/${note.id}/acknowledge`, {}).subscribe({
      next: () => { this.load(); },
      error: () => this.toast.error('Could not update'),
    });
  }

  typeColor(t: string): string { return TYPE_COLOR[t] ?? 'secondary'; }
  typeIcon(t: string): string { return TYPE_ICON[t] ?? 'chat'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
