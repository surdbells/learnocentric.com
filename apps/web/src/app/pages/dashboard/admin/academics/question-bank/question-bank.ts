import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {QuestionForm} from '../../../../../components/forms/question-form/question-form';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {Icon} from '../../../../../common/icon/icon';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark'};
const DIFF_COLOR: Record<string, string> = {foundational: 'success', moderate: 'info', challenging: 'warning', extension: 'danger'};
const TYPE_LABEL: Record<string, string> = {mcq: 'Multiple choice', true_false: 'True / False', short: 'Short answer', numeric: 'Numeric'};
const ACTION_LABEL: Record<string, string> = {review: 'Submit for review', approved: 'Approve', published: 'Publish', archived: 'Archive', draft: 'Return to draft'};
const APPROVER_ROLES = ['academic_lead', 'school_admin', 'tutor_admin', 'super_admin'];

@Component({
  selector: 'app-question-bank',
  standalone: true,
  imports: [Icon, PageHeader, LearnoModal, LearnoButton, DataGrid, QuestionForm, DatePipe, FormsModule],
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

  /** Unique subjects across the topic list, for the Subject filter (multi-subject teachers). */
  readonly subjectOptions = computed(() => {
    const seen = new Map<number, string>();
    for (const t of this.topics()) {
      if (t.subject_id && t.subject && !seen.has(t.subject_id)) { seen.set(t.subject_id, t.subject); }
    }
    return [...seen.entries()].map(([value, label]) => ({label, value: String(value)}));
  });

  readonly filterDefs = computed<GridFilter[]>(() => [
    ...(this.subjectOptions().length > 1 ? [{key: 'subject_id', label: 'Subject', options: this.subjectOptions()}] : []),
    {key: 'approval_status', label: 'Status', options: [
      {label: 'Draft', value: 'draft'}, {label: 'In review', value: 'review'}, {label: 'Approved', value: 'approved'},
      {label: 'Published', value: 'published'}, {label: 'Archived', value: 'archived'}]},
    {key: 'type', label: 'Type', options: [
      {label: 'Multiple choice', value: 'mcq'}, {label: 'True / False', value: 'true_false'},
      {label: 'Short answer', value: 'short'}, {label: 'Numeric', value: 'numeric'}]},
    {key: 'answer_validated', label: 'Answer', options: [{label: 'Validated', value: '1'}, {label: 'Unvalidated', value: '0'}]},
    {key: 'difficulty', label: 'Level', options: [
      {label: 'Foundational', value: 'foundational'}, {label: 'Moderate', value: 'moderate'},
      {label: 'Challenging', value: 'challenging'}, {label: 'Extension', value: 'extension'}]},
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

  // --- Bulk upload ---
  bulkTopicId = signal<number | null>(null);
  bulkText = signal<string>('');
  bulkParsed = signal<any[]>([]);
  bulkParseError = signal<string | null>(null);
  bulkBusy = signal(false);

  constructor() {
    this.api.get<any>('/backend/curriculum/topics').subscribe({
      next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? [])),
    });
  }

  onAdd(): void { this.selectQuestion.set(null); }

  openBulk(): void {
    this.bulkText.set('');
    this.bulkParsed.set([]);
    this.bulkParseError.set(null);
    this.open('bulk_upload');
  }

  onBulkFile(event: Event): void {
    const file = (event.target as HTMLInputElement)?.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => { this.bulkText.set(String(reader.result ?? '')); this.parseBulk(); };
    reader.readAsText(file);
  }

  /** Parse the pasted/uploaded CSV into a questions payload, with a live preview. */
  parseBulk(): void {
    this.bulkParseError.set(null);
    const rows = this.parseCsv(this.bulkText());
    if (rows.length < 2) { this.bulkParsed.set([]); this.bulkParseError.set('Add a header row plus at least one question row.'); return; }
    const header = rows[0].map(h => h.trim().toLowerCase());
    const col = (name: string) => header.indexOf(name);
    const iStem = col('stem');
    if (iStem < 0) { this.bulkParsed.set([]); this.bulkParseError.set('The CSV must include a "stem" column.'); return; }

    const out: any[] = [];
    for (let r = 1; r < rows.length; r++) {
      const row = rows[r];
      if (!row.length || row.every(c => !c.trim())) continue;
      const get = (n: string) => { const i = col(n); return i >= 0 ? (row[i] ?? '').trim() : ''; };
      const stem = (row[iStem] ?? '').trim();
      if (!stem) continue;
      const type = (get('type') || 'mcq').toLowerCase();
      const q: any = {stem, type, marks: Number(get('marks')) || 1, difficulty: get('difficulty') || 'moderate'};
      if (get('explanation')) q.explanation = get('explanation');
      if (get('topic_id')) q.topic_id = Number(get('topic_id'));

      if (type === 'mcq' || type === 'multi') {
        const options: any[] = [];
        for (const k of ['a', 'b', 'c', 'd']) { const text = get('option_' + k); if (text) options.push({key: k, text}); }
        q.options = options;
        const correct = get('correct');
        q.correct_answer = type === 'multi' ? correct.split(/[,;]/).map(s => s.trim().toLowerCase()).filter(Boolean) : correct.toLowerCase();
      } else if (type === 'true_false') {
        q.correct_answer = get('correct').toLowerCase();
      } else {
        q.correct_answer = get('correct');
      }
      out.push(q);
    }
    this.bulkParsed.set(out);
    if (!out.length) this.bulkParseError.set('No valid question rows found.');
  }

  submitBulk(): void {
    const questions = this.bulkParsed();
    if (!questions.length) { this.toast.error('Nothing to import, parse a CSV first.'); return; }
    if (!this.bulkTopicId()) { this.toast.error('Choose a default topic for rows without a topic_id.'); return; }
    this.bulkBusy.set(true);
    this.api.post<any>('/backend/assessment/questions/bulk', {topic_id: this.bulkTopicId(), questions}).subscribe({
      next: (res) => {
        this.bulkBusy.set(false);
        const errs = res?.errors?.length ?? 0;
        this.toast.success(`Imported ${res?.created ?? 0} question(s)` + (errs ? ` · ${errs} row(s) skipped` : ''));
        this.close('bulk_upload');
        this.grid?.refresh();
      },
      error: (e) => { this.bulkBusy.set(false); this.toast.error(e?.error?.error || 'Bulk import failed'); },
    });
  }

  downloadTemplate(): void {
    const header = 'stem,type,option_a,option_b,option_c,option_d,correct,marks,difficulty,explanation';
    const example = '"What is 12 ÷ 4?",mcq,2,3,4,6,c,1,moderate,"Divide 12 by 4."';
    const tf = '"The HCF of two primes is 1.",true_false,,,,,true,1,moderate,';
    const num = '"Write 2005 in figures.",numeric,,,,,2005,1,easy,';
    this.downloadBlob([header, example, tf, num].join('\n'), 'question-bank-template.csv');
  }

  private downloadBlob(text: string, filename: string): void {
    if (typeof window === 'undefined') return;
    const blob = new Blob([text], {type: 'text/csv'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
  }

  /** Minimal RFC-4180-ish CSV parser (handles quoted fields with commas + newlines). */
  private parseCsv(text: string): string[][] {
    const rows: string[][] = [];
    let row: string[] = [], field = '', inQuotes = false;
    const s = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    for (let i = 0; i < s.length; i++) {
      const c = s[i];
      if (inQuotes) {
        if (c === '"') { if (s[i + 1] === '"') { field += '"'; i++; } else inQuotes = false; }
        else field += c;
      } else if (c === '"') inQuotes = true;
      else if (c === ',') { row.push(field); field = ''; }
      else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
      else field += c;
    }
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    return rows.filter(r => r.length);
  }

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
