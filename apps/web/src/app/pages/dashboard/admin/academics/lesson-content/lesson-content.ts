import {Component, computed, inject, input, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {DeliveryPackForm} from '../../../../../components/forms/delivery-pack-form/delivery-pack-form';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {TabBar, TabItem} from '../../../../../common/ui';
import {Icon} from '../../../../../common/icon/icon';
import {Topics} from '../topics/topics';
import {ApiService} from '../../../../../common/service/api.service';
import {LifecyclePanel, LifecycleStep, lifecycleDone} from '../../../../../common/ui/lifecycle-panel';
import {AuthService} from '../../../../../common/auth/auth.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark'};
const APPROVER_ROLES = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

@Component({
  selector: 'app-lesson-content',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, DataGrid, DeliveryPackForm, DatePipe, RichText, TabBar, Icon, Topics, LifecyclePanel],
  templateUrl: './lesson-content.html',
  styleUrl: './lesson-content.css',
})
export class LessonContent {
  /** Hidden page header when hosted inside the Subjects & Curriculum hub. */
  readonly embedded = input<boolean>(false);

  @ViewChild(DataGrid) grid!: DataGrid;

  /**
   * Topics authoring is also reachable here as a tab, but the page opens on
   * Lesson content — clicking "Lesson Content" should show lesson content, not
   * topics (PDF review item 4).
   */
  tab = signal<string>('packs');
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

  // Approved/published packs are locked (PDF review R2-3). Editing one explains the
  // lock instead of hiding the button; teachers can't delete them, only admins can.
  readonly canDeletePack = (row: any): boolean => this.isApprover() || ['draft', 'review'].includes(row?.status);

  columns: GridColumn[] = [
    {key: 'topic', label: 'Topic', sortable: false},
    {key: 'subject', label: 'Subject'},
    {key: 'version', label: 'Version'},
    {key: 'status', label: 'Status', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: STATUS_COLOR[v] ?? 'secondary'})},
    {key: 'student_visible', label: 'Student view', type: 'badge', badge: (v) => v ? {text: 'Visible', color: 'success'} : {text: 'Hidden', color: 'secondary'}},
  ];

  // A learner sees a lesson only when BOTH its topic and its pack are published
  // (PDF review item 9). The lifecycle modal spells out exactly what's missing,
  // and the list carries a "Student view" badge from student_visible.

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'status', label: 'Status', options: [
      {label: 'Draft', value: 'draft'}, {label: 'In review', value: 'review'}, {label: 'Approved', value: 'approved'},
      {label: 'Published', value: 'published'}, {label: 'Archived', value: 'archived'}]},
  ]);

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
    // A locked lesson pack opens in its lifecycle view, which says why and what would unlock it.
    if (row?.lifecycle && !row.lifecycle.can_edit) {
      this.toast.info(row.lifecycle.edit_reason || 'This lesson pack cannot be edited right now.', 'Locked', {timeOut: 9000});
      this.onView(row);
      return;
    }
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

  /** Carry out a step chosen in the lifecycle panel (the server has already said it is allowed). */
  transition(step: LifecycleStep): void {
    const item = this.lifecyclePack();
    if (!item) return;
    this.transitioning.set(true);
    this.api.post<any>(`/backend/curriculum/delivery-packs/${item.id}/transition`, {to: step.to, note: step.note ?? null}).subscribe({
      next: (res) => {
        this.toast.success(lifecycleDone(res?.status, 'lesson pack'));
        this.lifecyclePack.set(res?.pack ?? item);
        this.loadHistory(item.id);
        this.grid?.refresh();
        this.transitioning.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'That step could not be completed'); this.transitioning.set(false); },
    });
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
