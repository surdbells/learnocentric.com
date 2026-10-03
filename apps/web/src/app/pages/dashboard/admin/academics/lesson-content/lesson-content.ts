import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {DeliveryPackForm} from '../../../../../components/forms/delivery-pack-form/delivery-pack-form';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {TabBar, TabItem} from '../../../../../common/ui';
import {Topics} from '../topics/topics';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark'};
const ACTION_LABEL: Record<string, string> = {review: 'Submit for review', approved: 'Approve', published: 'Publish', archived: 'Archive', draft: 'Return to draft'};
const APPROVER_ROLES = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

@Component({
  selector: 'app-lesson-content',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, DataGrid, DeliveryPackForm, DatePipe, RichText, TabBar, Topics],
  templateUrl: './lesson-content.html',
  styleUrl: './lesson-content.css',
})
export class LessonContent {
  @ViewChild(DataGrid) grid!: DataGrid;

  /** Topics authoring is now a tab on this page (PDF review T1). */
  tab = signal<string>('topics');
  readonly tabs: TabItem[] = [{key: 'topics', label: 'Topics'}, {key: 'packs', label: 'Lesson content'}];

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  selectPack = signal<any | null>(null);
  lifecyclePack = signal<any | null>(null);
  history = signal<any[]>([]);
  transitioning = signal(false);
  topics = signal<any[]>([]);
  packedTopicIds = signal<Set<number>>(new Set());

  readonly isApprover = computed(() => APPROVER_ROLES.includes(this.auth.getAuthSession()?.user?.role ?? ''));

  readonly availableTopics = computed(() => this.topics().filter((t) => !this.packedTopicIds().has(t.id)));

  columns: GridColumn[] = [
    {key: 'topic', label: 'Topic', sortable: false},
    {key: 'subject', label: 'Subject'},
    {key: 'version', label: 'Version'},
    {key: 'status', label: 'Status', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: STATUS_COLOR[v] ?? 'secondary'})},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'status', label: 'Status', options: [
      {label: 'Draft', value: 'draft'}, {label: 'In review', value: 'review'}, {label: 'Approved', value: 'approved'},
      {label: 'Published', value: 'published'}, {label: 'Archived', value: 'archived'}]},
  ]);

  readonly availableActions = computed(() => {
    const p = this.lifecyclePack();
    if (!p) return [];
    return (p.next_states ?? []).map((to: string) => ({to, label: ACTION_LABEL[to] ?? this.titleCase(to), enabled: this.canDo(p, to)}));
  });

  constructor() {
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.reloadPacked();
  }

  private reloadPacked(): void {
    this.api.get<any>('/backend/curriculum/delivery-packs').subscribe({
      next: (r) => {
        const list = Array.isArray(r) ? r : (r?.data ?? []);
        this.packedTopicIds.set(new Set(list.map((p: any) => p.topic_id)));
      },
    });
  }

  onAdd(): void { this.selectPack.set(null); }

  onEdit(row: any): void {
    this.selectPack.set(row);
    this.open('add_pack');
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    this.close('add_pack');
    this.selectPack.set(null);
    this.grid?.refresh();
    this.reloadPacked();
  }

  onView(row: any): void {
    this.lifecyclePack.set(row);
    this.history.set([]);
    this.loadHistory(row.id);
    this.open('pack_lifecycle');
  }

  private loadHistory(id: number): void {
    this.api.get<any[]>(`/backend/curriculum/delivery-packs/${id}/history`).subscribe({next: (h) => this.history.set(h ?? [])});
  }

  transition(to: string): void {
    const p = this.lifecyclePack();
    if (!p) return;
    this.transitioning.set(true);
    this.api.post<any>(`/backend/curriculum/delivery-packs/${p.id}/transition`, {to}).subscribe({
      next: (res) => {
        this.toast.success(`Pack ${res?.action ?? 'updated'}`);
        this.lifecyclePack.set(res?.pack ?? {...p, status: res?.status, next_states: res?.next});
        this.loadHistory(p.id);
        this.grid?.refresh();
        this.transitioning.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Transition failed'); this.transitioning.set(false); },
    });
  }

  canDo(p: any, to: string): boolean {
    if (p.status === 'draft' && to === 'review') return true;
    return this.isApprover();
  }

  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  private open(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  private close(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
  }
}
