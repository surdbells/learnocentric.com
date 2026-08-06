import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {WorksheetForm} from '../../../../../components/forms/worksheet-form/worksheet-form';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {Icon} from '../../../../../common/icon/icon';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark'};
const ACTION_LABEL: Record<string, string> = {review: 'Submit for review', approved: 'Approve', published: 'Publish', archived: 'Archive', draft: 'Return to draft'};
const APPROVER_ROLES = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

@Component({
  selector: 'app-worksheets',
  standalone: true,
  imports: [RichText, PageHeader, LearnoModal, LearnoButton, DataGrid, WorksheetForm, FormsModule, DatePipe, Icon],
  templateUrl: './worksheets.html',
  styleUrl: './worksheets.css',
})
export class Worksheets {
  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  selectWorksheet = signal<any | null>(null);
  manage = signal<any | null>(null);
  history = signal<any[]>([]);
  submissions = signal<any[]>([]);
  summary = signal<any | null>(null);
  gradeDraft = signal<Record<number, { score: any; feedback: string }>>({});
  busy = signal(false);
  topics = signal<any[]>([]);

  // Per-question marking (structured worksheets)
  wsQuestionCount = signal(0);
  markSubId = signal<number | null>(null);
  markDetail = signal<any | null>(null);
  markDraft = signal<Record<number, number>>({});
  markFeedback = signal('');
  markBusy = signal(false);

  readonly isApprover = computed(() => APPROVER_ROLES.includes(this.auth.getAuthSession()?.user?.role ?? ''));

  columns: GridColumn[] = [
    {key: 'title', label: 'Worksheet', sortable: true},
    {key: 'topic', label: 'Topic'},
    {key: 'total_marks', label: 'Marks'},
    {key: 'due_date', label: 'Due', type: 'date'},
    {key: 'approval_status', label: 'Status', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: STATUS_COLOR[v] ?? 'secondary'})},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'approval_status', label: 'Status', options: [
      {label: 'Draft', value: 'draft'}, {label: 'In review', value: 'review'}, {label: 'Approved', value: 'approved'},
      {label: 'Published', value: 'published'}, {label: 'Archived', value: 'archived'}]},
    {key: 'track', label: 'Track', options: [{label: 'Academic', value: 'academic'}, {label: 'Competency', value: 'competency'}]},
  ]);

  readonly availableActions = computed(() => {
    const w = this.manage();
    if (!w) return [];
    return (w.next_states ?? []).map((to: string) => ({to, label: ACTION_LABEL[to] ?? this.titleCase(to), enabled: this.canDo(w, to)}));
  });

  constructor() {
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? []))});
  }

  onAdd(): void { this.selectWorksheet.set(null); }

  onEdit(row: any): void {
    this.selectWorksheet.set(row);
    this.open('add_worksheet');
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    this.close('add_worksheet');
    this.selectWorksheet.set(null);
    this.grid?.refresh();
  }

  onView(row: any): void {
    this.manage.set(row);
    this.history.set([]);
    this.submissions.set([]);
    this.summary.set(null);
    this.loadHistory(row.id);
    this.loadSubmissions(row.id);
    this.open('worksheet_manage');
  }

  private loadHistory(id: number): void {
    this.api.get<any[]>(`/backend/assessment/worksheets/${id}/history`).subscribe({next: (h) => this.history.set(h ?? [])});
  }

  private loadSubmissions(id: number): void {
    this.api.get<any>(`/backend/assessment/worksheets/${id}/submissions`).subscribe({
      next: (res) => {
        this.submissions.set(res?.data ?? []);
        this.summary.set(res?.summary ?? null);
        this.wsQuestionCount.set(res?.worksheet?.question_count ?? 0);
        const draft: Record<number, { score: any; feedback: string }> = {};
        (res?.data ?? []).forEach((s: any) => draft[s.id] = {score: s.score, feedback: s.feedback ?? ''});
        this.gradeDraft.set(draft);
      },
    });
  }

  setScore(id: number, score: any): void { this.gradeDraft.set({...this.gradeDraft(), [id]: {...this.gradeDraft()[id], score}}); }
  setFeedback(id: number, feedback: string): void { this.gradeDraft.set({...this.gradeDraft(), [id]: {...this.gradeDraft()[id], feedback}}); }

  grade(sub: any): void {
    const draft = this.gradeDraft()[sub.id];
    if (draft?.score === null || draft?.score === undefined || draft?.score === '') {
      this.toast.error('Enter a score');
      return;
    }
    this.busy.set(true);
    this.api.post<any>(`/backend/assessment/worksheet-submissions/${sub.id}/grade`, {score: Number(draft.score), feedback: draft.feedback}).subscribe({
      next: () => { this.toast.success('Graded'); this.loadSubmissions(this.manage().id); this.busy.set(false); },
      error: (e) => { this.toast.error(e?.error?.error || 'Grading failed'); this.busy.set(false); },
    });
  }

  // --- Per-question marking ---

  openMark(sub: any): void {
    if (this.markSubId() === sub.id) { this.markSubId.set(null); return; } // toggle closed
    this.markSubId.set(sub.id);
    this.markDetail.set(null);
    this.api.get<any>(`/backend/assessment/worksheet-submissions/${sub.id}/detail`).subscribe({
      next: (d) => {
        this.markDetail.set(d);
        const draft: Record<number, number> = {};
        (d?.questions ?? []).forEach((q: any) => draft[q.question_id] = q.awarded_marks ?? 0);
        this.markDraft.set(draft);
        this.markFeedback.set(d?.submission?.feedback ?? '');
      },
      error: () => this.toast.error('Could not load the submission'),
    });
  }

  setMark(qid: number, val: any): void {
    this.markDraft.set({...this.markDraft(), [qid]: val === '' || val == null ? 0 : Number(val)});
  }

  markTotal(): number {
    return Object.values(this.markDraft()).reduce((s, v) => s + (Number(v) || 0), 0);
  }

  saveMarks(): void {
    const subId = this.markSubId();
    const d = this.markDetail();
    if (!subId || !d) return;
    const marks = (d.questions ?? [])
      .filter((q: any) => !q.auto) // only free-response is teacher-marked; objective stays auto
      .map((q: any) => ({question_id: q.question_id, awarded_marks: this.markDraft()[q.question_id] ?? 0}));
    this.markBusy.set(true);
    this.api.post<any>(`/backend/assessment/worksheet-submissions/${subId}/grade-questions`, {marks, feedback: this.markFeedback()}).subscribe({
      next: () => {
        this.toast.success('Marks saved');
        this.markBusy.set(false);
        this.markSubId.set(null);
        this.loadSubmissions(this.manage().id);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not save marks'); this.markBusy.set(false); },
    });
  }

  transition(to: string): void {
    const w = this.manage();
    if (!w) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/assessment/worksheets/${w.id}/transition`, {to}).subscribe({
      next: (res) => {
        this.toast.success(`Worksheet ${res?.action ?? 'updated'}`);
        this.manage.set(res?.worksheet ?? w);
        this.loadHistory(w.id);
        this.grid?.refresh();
        this.busy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Transition failed'); this.busy.set(false); },
    });
  }

  canDo(w: any, to: string): boolean {
    if (w.approval_status === 'draft' && to === 'review') return true;
    return this.isApprover();
  }

  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }
  subColor(s: string): string { return s === 'graded' ? 'success' : 'warning'; }
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
