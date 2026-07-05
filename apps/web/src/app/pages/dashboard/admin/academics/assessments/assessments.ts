import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {AssessmentForm} from '../../../../../components/forms/assessment-form/assessment-form';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark'};
const ACTION_LABEL: Record<string, string> = {review: 'Submit for review', approved: 'Approve', published: 'Publish', archived: 'Archive', draft: 'Return to draft'};
const APPROVER_ROLES = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

@Component({
  selector: 'app-assessments',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, DataGrid, AssessmentForm, FormsModule],
  templateUrl: './assessments.html',
  styleUrl: './assessments.css',
})
export class Assessments {
  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  selectAssessment = signal<any | null>(null);
  builder = signal<any | null>(null);
  available = signal<any[]>([]);
  pickId = signal<number | null>(null);
  busy = signal(false);
  subjects = signal<any[]>([]);
  topics = signal<any[]>([]);

  readonly isApprover = computed(() => APPROVER_ROLES.includes(this.auth.getAuthSession()?.user?.role ?? ''));
  readonly editable = computed(() => ['draft', 'review'].includes(this.builder()?.approval_status));

  columns: GridColumn[] = [
    {key: 'title', label: 'Title', sortable: true},
    {key: 'type', label: 'Type', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: 'secondary'})},
    {key: 'subject', label: 'Subject'},
    {key: 'question_count', label: 'Questions'},
    {key: 'total_marks', label: 'Marks'},
    {key: 'approval_status', label: 'Status', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: STATUS_COLOR[v] ?? 'secondary'})},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'approval_status', label: 'Status', options: [
      {label: 'Draft', value: 'draft'}, {label: 'In review', value: 'review'}, {label: 'Approved', value: 'approved'},
      {label: 'Published', value: 'published'}, {label: 'Archived', value: 'archived'}]},
    {key: 'type', label: 'Type', options: [
      {label: 'Quiz', value: 'quiz'}, {label: 'Test', value: 'test'}, {label: 'Exam', value: 'exam'}, {label: 'Worksheet', value: 'worksheet'}]},
    {key: 'subject_id', label: 'Subject', options: this.subjects().map((s) => ({label: s.name, value: String(s.id)}))},
  ]);

  readonly availableActions = computed(() => {
    const a = this.builder();
    if (!a) return [];
    return (a.next_states ?? []).map((to: string) => ({
      to,
      label: ACTION_LABEL[to] ?? this.titleCase(to),
      enabled: this.canDo(a, to),
    }));
  });

  constructor() {
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? []))});
  }

  onAdd(): void { this.selectAssessment.set(null); }

  onEdit(row: any): void {
    this.selectAssessment.set(row);
    this.open('add_assessment');
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    this.close('add_assessment');
    this.selectAssessment.set(null);
    this.grid?.refresh();
  }

  onView(row: any): void {
    this.builder.set(null);
    this.available.set([]);
    this.pickId.set(null);
    this.loadDetail(row.id);
    this.open('assessment_builder');
  }

  private loadDetail(id: number): void {
    this.api.get<any>(`/backend/assessment/assessments/${id}`).subscribe({
      next: (a) => { this.builder.set(a); this.loadAvailable(a); },
    });
  }

  private loadAvailable(a: any): void {
    const attached = new Set((a.questions ?? []).map((q: any) => q.question_id));
    this.api.get<any>('/backend/assessment/questions', {params: {subject_id: a.subject_id, answer_validated: 1, per_page: 200}}).subscribe({
      next: (res) => {
        const rows = res?.data ?? res ?? [];
        this.available.set(rows.filter((q: any) => !attached.has(q.id)));
      },
    });
  }

  attach(): void {
    const a = this.builder();
    const qid = this.pickId();
    if (!a || !qid) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/assessment/assessments/${a.id}/questions`, {question_id: qid}).subscribe({
      next: (detail) => {
        this.builder.set(detail);
        this.loadAvailable(detail);
        this.pickId.set(null);
        this.grid?.refresh();
        this.busy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not add question'); this.busy.set(false); },
    });
  }

  detach(qid: number): void {
    const a = this.builder();
    if (!a) return;
    this.busy.set(true);
    this.api.delete<any>(`/backend/assessment/assessments/${a.id}/questions/${qid}`).subscribe({
      next: (detail) => { this.builder.set(detail); this.loadAvailable(detail); this.grid?.refresh(); this.busy.set(false); },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not remove question'); this.busy.set(false); },
    });
  }

  transition(to: string): void {
    const a = this.builder();
    if (!a) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/assessment/assessments/${a.id}/transition`, {to}).subscribe({
      next: (res) => {
        this.toast.success(`Assessment ${res?.action ?? 'updated'}`);
        this.builder.set(res?.assessment ?? a);
        this.grid?.refresh();
        this.busy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Transition failed'); this.busy.set(false); },
    });
  }

  canDo(a: any, to: string): boolean {
    if (a.approval_status === 'draft' && to === 'review') return true;
    if ((to === 'approved' || to === 'published') && ((a.unvalidated_questions?.length ?? 0) > 0 || (a.question_count ?? 0) === 0)) return false;
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
