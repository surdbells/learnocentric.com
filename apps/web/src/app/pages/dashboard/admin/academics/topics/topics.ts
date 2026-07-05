import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {TopicForm} from '../../../../../components/forms/topic-form/topic-form';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {
  draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark',
};
const ACTION_LABEL: Record<string, string> = {
  review: 'Submit for review', approved: 'Approve', published: 'Publish', archived: 'Archive', draft: 'Return to draft',
};
const APPROVER_ROLES = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

@Component({
  selector: 'app-topics',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, DataGrid, TopicForm, DatePipe],
  templateUrl: './topics.html',
  styleUrl: './topics.css',
})
export class Topics {
  @ViewChild(DataGrid) grid!: DataGrid;

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

  columns: GridColumn[] = [
    {key: 'title', label: 'Topic', sortable: true},
    {key: 'subject', label: 'Subject'},
    {key: 'week_number', label: 'Week', sortable: true},
    {key: 'version_count', label: 'Versions'},
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

  readonly availableActions = computed(() => {
    const t = this.lifecycleTopic();
    if (!t) return [];
    return (t.next_states ?? []).map((to: string) => ({
      to,
      label: ACTION_LABEL[to] ?? this.titleCase(to),
      enabled: this.canDo(t.approval_status, to),
    }));
  });

  constructor() {
    this.api.get<any>('/backend/school/subjects').subscribe({
      next: (r) => this.subjects.set(Array.isArray(r) ? r : (r?.data ?? [])),
    });
  }

  onAdd(): void {
    this.selectTopic.set(null);
  }

  onEdit(row: any): void {
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

  transition(to: string): void {
    const t = this.lifecycleTopic();
    if (!t) return;
    this.transitioning.set(true);
    this.api.post<any>(`/backend/curriculum/topics/${t.id}/transition`, {to}).subscribe({
      next: (res) => {
        this.toast.success(`Topic ${res?.action ?? 'updated'}`);
        this.lifecycleTopic.set(res?.topic ?? {...t, approval_status: res?.status, next_states: res?.next});
        this.loadHistory(t.id);
        this.grid?.refresh();
        this.transitioning.set(false);
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Transition failed');
        this.transitioning.set(false);
      },
    });
  }

  private loadHistory(id: number): void {
    this.historyLoading.set(true);
    this.api.get<any[]>(`/backend/curriculum/topics/${id}/history`).subscribe({
      next: (h) => { this.history.set(h ?? []); this.historyLoading.set(false); },
      error: () => this.historyLoading.set(false),
    });
  }

  canDo(from: string, to: string): boolean {
    if (from === 'draft' && to === 'review') return true; // authors may submit
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
