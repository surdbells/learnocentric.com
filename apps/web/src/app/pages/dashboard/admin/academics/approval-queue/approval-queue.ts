import {Component, computed, inject, signal, PLATFORM_ID} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../../common/ui';

/** Governed type -> its REST base (the transition endpoint is `${base}/${id}/transition`). */
const TYPE_ROUTE: Record<string, string> = {
  Topic: '/backend/curriculum/topics',
  TopicDeliveryPack: '/backend/curriculum/delivery-packs',
  Question: '/backend/assessment/questions',
  Assessment: '/backend/assessment/assessments',
  Worksheet: '/backend/assessment/worksheets',
  SchemeOfWork: '/backend/school/scheme-of-work',
  ContentPackage: '/backend/content/packages',
};

const TYPE_META: Record<string, {label: string; icon: string}> = {
  Topic: {label: 'Topic', icon: 'subject'},
  TopicDeliveryPack: {label: 'Delivery pack', icon: 'layers'},
  Question: {label: 'Question', icon: 'quiz'},
  Assessment: {label: 'Assessment', icon: 'assignment'},
  Worksheet: {label: 'Worksheet', icon: 'description'},
  SchemeOfWork: {label: 'Scheme of work', icon: 'calendar_month'},
  ContentPackage: {label: 'Content package', icon: 'folder'},
};

@Component({
  selector: 'app-approval-queue',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, KpiStrip, TabBar],
  templateUrl: './approval-queue.html',
  styleUrl: './approval-queue.css',
})
export class ApprovalQueue {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  items = signal<any[]>([]);
  activeTab = signal<string>('all');
  busyKey = signal<string | null>(null); // `${type}:${id}` currently transitioning

  readonly kpis = computed<KpiItem[]>(() => {
    const it = this.items();
    const oldest = it.length ? it.reduce((a, b) => a.updated_at < b.updated_at ? a : b) : null;
    return [
      {label: 'Awaiting approval', value: it.length, icon: 'inbox', tone: it.length > 0 ? 'warning' : 'success'},
      {label: 'Content types', value: new Set(it.map(x => x.type)).size, icon: 'layers', tone: 'info'},
      {label: 'Assessments', value: it.filter(x => x.type === 'Assessment' || x.type === 'Question').length, icon: 'quiz', tone: 'primary'},
      {label: 'Oldest waiting', value: oldest ? this.waited(oldest.updated_at) : '—', icon: 'schedule', tone: 'secondary'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const it = this.items();
    const types = [...new Set(it.map(x => x.type))] as string[];
    return [
      {key: 'all', label: 'All', count: it.length},
      ...types.map(t => ({key: t, label: this.typeLabel(t), count: it.filter(x => x.type === t).length})),
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), it = this.items();
    return t === 'all' ? it : it.filter(x => x.type === t);
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/curriculum/review-queue').subscribe({
      next: (r) => { this.items.set(r?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the approval queue'); },
    });
  }

  approve(item: any): void {
    this.transition(item, 'approved', null, 'approved');
  }

  return(item: any): void {
    const note = isPlatformBrowser(this.platformId)
      ? (window.prompt('Reason for returning to the author (optional):') ?? '')
      : '';
    // A null return from prompt means the user cancelled.
    if (isPlatformBrowser(this.platformId) && note === null) return;
    this.transition(item, 'draft', note, 'returned to the author');
  }

  private transition(item: any, to: string, note: string | null, verb: string): void {
    const base = TYPE_ROUTE[item.type];
    if (!base) { this.toast.error('This item type cannot be actioned here.'); return; }
    const key = `${item.type}:${item.id}`;
    this.busyKey.set(key);
    this.api.post<any>(`${base}/${item.id}/transition`, {to, note}).subscribe({
      next: () => {
        this.busyKey.set(null);
        this.toast.success(`${this.typeLabel(item.type)} ${verb}`);
        // Drop it from the queue — it's no longer in review.
        this.items.set(this.items().filter(x => !(x.type === item.type && x.id === item.id)));
      },
      error: (e) => { this.busyKey.set(null); this.toast.error(e?.error?.error || 'Could not update this item'); },
    });
  }

  isBusy(item: any): boolean { return this.busyKey() === `${item.type}:${item.id}`; }
  typeLabel(t: string): string { return TYPE_META[t]?.label ?? t; }
  typeIcon(t: string): string { return TYPE_META[t]?.icon ?? 'description'; }

  /** Human "waiting for" label from an ISO timestamp. */
  waited(iso: string): string {
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
    if (days <= 0) return 'today';
    if (days === 1) return '1 day';
    return `${days} days`;
  }
}
