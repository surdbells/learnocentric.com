import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {QuestionForm} from '../../../../../components/forms/question-form/question-form';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark'};
const DIFF_COLOR: Record<string, string> = {easy: 'success', medium: 'warning', hard: 'danger'};
const TYPE_LABEL: Record<string, string> = {mcq: 'Multiple choice', true_false: 'True / False', short: 'Short answer', numeric: 'Numeric'};
const ACTION_LABEL: Record<string, string> = {review: 'Submit for review', approved: 'Approve', published: 'Publish', archived: 'Archive', draft: 'Return to draft'};
const APPROVER_ROLES = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

@Component({
  selector: 'app-question-bank',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, DataGrid, QuestionForm, DatePipe],
  templateUrl: './question-bank.html',
  styleUrl: './question-bank.css',
})
export class QuestionBank {
  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  selectQuestion = signal<any | null>(null);
  lifecycleQuestion = signal<any | null>(null);
  history = signal<any[]>([]);
  historyLoading = signal(false);
  transitioning = signal(false);
  validating = signal(false);
  topics = signal<any[]>([]);

  readonly isApprover = computed(() => APPROVER_ROLES.includes(this.auth.getAuthSession()?.user?.role ?? ''));

  columns: GridColumn[] = [
    {key: 'stem', label: 'Question', sortable: true},
    {key: 'type', label: 'Type', type: 'badge', badge: (v) => ({text: TYPE_LABEL[v] ?? v, color: 'secondary'})},
    {key: 'topic', label: 'Topic'},
    {key: 'difficulty', label: 'Level', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: DIFF_COLOR[v] ?? 'secondary'})},
    {key: 'marks', label: 'Marks'},
    {key: 'answer_validated', label: 'Answer', type: 'badge', badge: (v) => v ? {text: 'Validated', color: 'success'} : {text: 'Unvalidated', color: 'warning'}},
    {key: 'approval_status', label: 'Status', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: STATUS_COLOR[v] ?? 'secondary'})},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'approval_status', label: 'Status', options: [
      {label: 'Draft', value: 'draft'}, {label: 'In review', value: 'review'}, {label: 'Approved', value: 'approved'},
      {label: 'Published', value: 'published'}, {label: 'Archived', value: 'archived'}]},
    {key: 'type', label: 'Type', options: [
      {label: 'Multiple choice', value: 'mcq'}, {label: 'True / False', value: 'true_false'},
      {label: 'Short answer', value: 'short'}, {label: 'Numeric', value: 'numeric'}]},
    {key: 'answer_validated', label: 'Answer', options: [{label: 'Validated', value: '1'}, {label: 'Unvalidated', value: '0'}]},
    {key: 'difficulty', label: 'Level', options: [{label: 'Easy', value: 'easy'}, {label: 'Medium', value: 'medium'}, {label: 'Hard', value: 'hard'}]},
  ]);

  readonly availableActions = computed(() => {
    const q = this.lifecycleQuestion();
    if (!q) return [];
    return (q.next_states ?? []).map((to: string) => ({
      to,
      label: ACTION_LABEL[to] ?? this.titleCase(to),
      enabled: this.canDo(q, to),
    }));
  });

  constructor() {
    this.api.get<any>('/backend/curriculum/topics').subscribe({
      next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? [])),
    });
  }

  onAdd(): void { this.selectQuestion.set(null); }

  onEdit(row: any): void {
    this.selectQuestion.set(row);
    this.open('add_question');
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    this.close('add_question');
    this.selectQuestion.set(null);
    this.grid?.refresh();
  }

  onView(row: any): void {
    this.lifecycleQuestion.set(row);
    this.history.set([]);
    this.loadHistory(row.id);
    this.open('question_lifecycle');
  }

  validate(): void {
    const q = this.lifecycleQuestion();
    if (!q) return;
    this.validating.set(true);
    this.api.post<any>(`/backend/assessment/questions/${q.id}/validate`, {}).subscribe({
      next: (res) => {
        this.toast.success('Answer validated');
        this.lifecycleQuestion.set(res);
        this.grid?.refresh();
        this.validating.set(false);
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Validation failed');
        this.validating.set(false);
      },
    });
  }

  transition(to: string): void {
    const q = this.lifecycleQuestion();
    if (!q) return;
    this.transitioning.set(true);
    this.api.post<any>(`/backend/assessment/questions/${q.id}/transition`, {to}).subscribe({
      next: (res) => {
        this.toast.success(`Question ${res?.action ?? 'updated'}`);
        this.lifecycleQuestion.set(res?.question ?? {...q, approval_status: res?.status, next_states: res?.next});
        this.loadHistory(q.id);
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
    this.api.get<any[]>(`/backend/assessment/questions/${id}/history`).subscribe({
      next: (h) => { this.history.set(h ?? []); this.historyLoading.set(false); },
      error: () => this.historyLoading.set(false),
    });
  }

  canDo(q: any, to: string): boolean {
    if (q.approval_status === 'draft' && to === 'review') return true; // authors may submit
    if ((to === 'approved' || to === 'published') && !q.answer_validated) return false; // the gate
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
