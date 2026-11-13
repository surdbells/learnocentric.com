import {Component, computed, inject, input, signal, ViewChild} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {SchemeForm} from '../../../../../components/forms/scheme-form/scheme-form';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {LifecyclePanel, LifecycleStep, lifecycleDone} from '../../../../../common/ui/lifecycle-panel';
import {ToastrService} from 'ngx-toastr';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark'};
const STATUS_LABEL: Record<string, string> = {draft: 'Draft', review: 'In review', approved: 'Approved', published: 'Published', archived: 'Archived'};

@Component({
  selector: 'app-scheme-of-work',
  standalone: true,
  imports: [PageHeader, LearnoModal, SchemeForm, LearnoButton, DataGrid, Icon, LifecyclePanel],
  templateUrl: './scheme-of-work.html',
  styleUrl: './scheme-of-work.css',
})
export class SchemeOfWork {
  /** Hidden page header when hosted inside the Subjects & Curriculum hub. */
  readonly embedded = input<boolean>(false);

  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  /** The week whose status is being viewed or moved. */
  manage = signal<any | null>(null);
  busy = signal(false);

  selectScheme = signal<any | null>(null);
  classes = signal<any[]>([]);
  subjects = signal<any[]>([]);
  terms = signal<any[]>([]);
  topics = signal<any[]>([]);
  teachers = signal<any[]>([]);

  columns: GridColumn[] = [
    {key: 'week_number', label: 'Week', sortable: true},
    {key: 'topic', label: 'Topic'},
    {key: 'class_label', label: 'Class'},
    {key: 'subject', label: 'Subject'},
    {key: 'objective', label: 'Objective'},
    {key: 'status', label: 'Status', type: 'badge', badge: (v) => ({text: STATUS_LABEL[v] ?? v, color: STATUS_COLOR[v] ?? 'secondary'})},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'class_id', label: 'Class', options: this.classes().map((c) => ({label: c.label || c.name, value: String(c.id)}))},
    {key: 'subject_id', label: 'Subject', options: this.subjects().map((s) => ({label: s.name, value: String(s.id)}))},
    {key: 'status', label: 'Status', options: [
      {label: 'Draft', value: 'draft'}, {label: 'In review', value: 'review'}, {label: 'Approved', value: 'approved'},
      {label: 'Published', value: 'published'}, {label: 'Archived', value: 'archived'}]},
  ]);

  constructor() {
    this.api.get<any>('/backend/school/classes').subscribe({next: (r) => this.classes.set(this.list(r))});
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(this.list(r))});
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(this.list(r))});
    this.api.get<any>('/backend/school/teachers').subscribe({next: (r) => this.teachers.set(this.list(r))});
    this.api.get<any>('/backend/school/terms').subscribe({
      next: (r) => this.terms.set(this.list(r)),
      error: () => this.terms.set([]),
    });
  }

  private list(r: any): any[] { return Array.isArray(r) ? r : (r?.data ?? []); }

  onAdd(): void { this.selectScheme.set(null); }

  onEdit(row: any): void {
    // A locked week opens in its status view, which says why and what would unlock it.
    if (row?.lifecycle && !row.lifecycle.can_edit) {
      this.toast.info(row.lifecycle.edit_reason || 'This week cannot be edited right now.', 'Locked', {timeOut: 9000});
      this.onView(row);
      return;
    }
    this.selectScheme.set(row);
    const el = document.getElementById('add_week');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  /** Open a week's status: where it stands, and the steps to submit, approve or publish it. */
  onView(row: any): void {
    this.manage.set(row);
    const el = document.getElementById('scheme_status');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  /** Carry out a step chosen in the lifecycle panel (the server has already said it is allowed). */
  transition(step: LifecycleStep): void {
    const item = this.manage();
    if (!item) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/school/scheme-of-work/${item.id}/transition`, {to: step.to, note: step.note ?? null}).subscribe({
      next: (res) => {
        this.toast.success(lifecycleDone(res?.status, 'scheme entry'));
        this.manage.set(res?.scheme ?? item);
        this.grid?.refresh();
        this.busy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'That step could not be completed'); this.busy.set(false); },
    });
  }

  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    const el = document.getElementById('add_week');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
    this.selectScheme.set(null);
    this.grid?.refresh();
  }
}
