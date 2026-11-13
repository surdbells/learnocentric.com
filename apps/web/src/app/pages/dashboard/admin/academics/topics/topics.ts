import {Component, computed, inject, input, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {TopicForm} from '../../../../../components/forms/topic-form/topic-form';
import {ApiService} from '../../../../../common/service/api.service';
import {LifecyclePanel, LifecycleStep, lifecycleDone} from '../../../../../common/ui/lifecycle-panel';
import {AuthService} from '../../../../../common/auth/auth.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {
  draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark',
};
const APPROVER_ROLES = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

@Component({
  selector: 'app-topics',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, DataGrid, TopicForm, DatePipe, LifecyclePanel],
  templateUrl: './topics.html',
  styleUrl: './topics.css',
})
export class Topics {
  @ViewChild(DataGrid) grid!: DataGrid;

  /** When hosted inside another page (e.g. the Lesson Content tabs), hide the page header. */
  readonly embedded = input<boolean>(false);

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  selectTopic = signal<any | null>(null);
  lifecycleTopic = signal<any | null>(null);
  history = signal<any[]>([]);
  historyLoading = signal(false);
  transitioning = signal(false);
  subjects = signal<any[]>([]);

  readonly isApprover = computed(() => APPROVER_ROLES.includes(this.auth.getAuthSession()?.user?.role ?? ''));

  // Approved/published topics are locked (PDF review R2-3). Editing one explains the
  // lock instead of hiding the button; teachers can't delete approved ones, only admins can.
  readonly canDeleteTopic = (row: any): boolean => this.isApprover() || ['draft', 'review'].includes(row?.approval_status);

  columns: GridColumn[] = [
    {key: 'title', label: 'Topic', sortable: true},
    {key: 'subject', label: 'Subject'},
    {key: 'class_label', label: 'Class', type: 'badge', badge: (v) => v ? {text: v, color: 'info'} : {text: 'All classes', color: 'secondary'}},
    {key: 'week_number', label: 'Week', sortable: true},
    {key: 'approval_status', label: 'Status', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: STATUS_COLOR[v] ?? 'secondary'})},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {
      key: 'approval_status', label: 'Status', options: [
        {label: 'Draft', value: 'draft'}, {label: 'In review', value: 'review'}, {label: 'Approved', value: 'approved'},
        {label: 'Published', value: 'published'}, {label: 'Archived', value: 'archived'},
      ],
    },
    {key: 'subject_id', label: 'Subject', options: this.subjects().map(s => ({label: s.name, value: String(s.id)}))},
  ]);

  constructor() {
    this.api.get<any>('/backend/school/subjects').subscribe({
      next: (r) => this.subjects.set(Array.isArray(r) ? r : (r?.data ?? [])),
    });
  }

  onAdd(): void {
    this.selectTopic.set(null);
  }

  onEdit(row: any): void {
    // A locked topic opens in its lifecycle view, which says why and what would unlock it.
    if (row?.lifecycle && !row.lifecycle.can_edit) {
      this.toast.info(row.lifecycle.edit_reason || 'This topic cannot be edited right now.', 'Locked', {timeOut: 9000});
      this.onView(row);
      return;
    }
    this.selectTopic.set(row);
    this.open('add_topic');
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    this.close('add_topic');
    this.selectTopic.set(null);
    this.grid?.refresh();
  }

  onView(row: any): void {
    this.lifecycleTopic.set(row);
    this.history.set([]);
    this.loadHistory(row.id);
    this.open('topic_lifecycle');
  }

  /** Carry out a step chosen in the lifecycle panel (the server has already said it is allowed). */
  transition(step: LifecycleStep): void {
    const item = this.lifecycleTopic();
    if (!item) return;
    this.transitioning.set(true);
    this.api.post<any>(`/backend/curriculum/topics/${item.id}/transition`, {to: step.to, note: step.note ?? null}).subscribe({
      next: (res) => {
        this.toast.success(lifecycleDone(res?.status, 'topic'));
        this.lifecycleTopic.set(res?.topic ?? item);
        this.loadHistory(item.id);
        this.grid?.refresh();
        this.transitioning.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'That step could not be completed'); this.transitioning.set(false); },
    });
  }

  private loadHistory(id: number): void {
    this.historyLoading.set(true);
    this.api.get<any[]>(`/backend/curriculum/topics/${id}/history`).subscribe({
      next: (h) => { this.history.set(h ?? []); this.historyLoading.set(false); },
      error: () => this.historyLoading.set(false),
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
