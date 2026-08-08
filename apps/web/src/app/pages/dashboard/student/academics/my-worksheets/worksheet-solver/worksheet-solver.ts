import {Component, computed, inject, input, output, signal, OnInit, OnDestroy, PLATFORM_ID} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {Subject} from 'rxjs';
import {debounceTime} from 'rxjs/operators';
import {ToastrService} from 'ngx-toastr';
import {Icon} from '../../../../../../common/icon/icon';
import {ApiService} from '../../../../../../common/service/api.service';

/**
 * Rich worksheet solver (design: Worksheet II_LD) — sectioned questions with
 * per-type answer inputs, a progress ring, a question navigator, autosave, and
 * submit with hybrid grading (objective auto-scored, free-response deferred to
 * the teacher). Read-only once submitted/graded, showing per-question marks.
 */
@Component({
  selector: 'app-worksheet-solver',
  standalone: true,
  imports: [Icon, FormsModule],
  templateUrl: './worksheet-solver.html',
  styleUrl: './worksheet-solver.css',
})
export class WorksheetSolver implements OnInit, OnDestroy {
  worksheetId = input.required<number>();
  done = output<void>();

  private api = inject(ApiService);
  private toast = inject(ToastrService);
  private platformId = inject(PLATFORM_ID);

  loading = signal(true);
  submitting = signal(false);
  saving = signal(false);
  savedAt = signal<string | null>(null);
  data = signal<any>(null);
  answers = signal<Record<number, string>>({});
  activeQ = signal<number | null>(null);

  private save$ = new Subject<void>();

  constructor() {
    this.save$.pipe(debounceTime(800)).subscribe(() => this.autosave());
  }

  ngOnInit(): void { this.load(); }
  ngOnDestroy(): void { this.save$.complete(); }

  private load(): void {
    this.loading.set(true);
    this.api.get<any>(`/backend/assessment/worksheets/${this.worksheetId()}/solve`).subscribe({
      next: (res) => {
        this.data.set(res);
        const a: Record<number, string> = {};
        for (const [qid, r] of Object.entries(res?.responses ?? {})) {
          if ((r as any)?.answer != null) a[+qid] = String((r as any).answer);
        }
        this.answers.set(a);
        const first = this.flatQuestions()[0];
        if (first) this.activeQ.set(first.id);
        this.loading.set(false);
      },
      error: () => { this.toast.error('Could not load this worksheet'); this.loading.set(false); this.done.emit(); },
    });
  }

  readOnly = computed(() => ['submitted', 'graded'].includes(this.data()?.status));

  flatQuestions = computed<any[]>(() => (this.data()?.sections ?? []).flatMap((s: any) => s.questions));

  totalQuestions = computed(() => this.flatQuestions().length);
  answeredCount = computed(() => this.flatQuestions().filter((q: any) => this.answerStr(q.id) !== '').length);

  /** Answers may be numbers (numeric inputs) or strings — always read as a trimmed string. */
  answerStr(qid: number): string { return String(this.answers()[qid] ?? '').trim(); }
  /** Raw answer for ngModel binding (empty string when unanswered). */
  answerFor(qid: number): string { return this.answers()[qid] ?? ''; }
  progressPct = computed(() => {
    const t = this.totalQuestions();
    return t ? Math.round((this.answeredCount() / t) * 100) : 0;
  });
  totalMarks = computed(() => this.data()?.progress?.total_marks ?? 0);
  marksObtained = computed(() => this.data()?.progress?.marks_obtained ?? 0);

  /** 1-based display index for a question id. */
  indexOf(qid: number): number {
    return this.flatQuestions().findIndex((q: any) => q.id === qid) + 1;
  }

  response(qid: number): any { return this.data()?.responses?.[qid] ?? null; }

  setAnswer(qid: number, value: string): void {
    if (this.readOnly()) return;
    this.answers.set({...this.answers(), [qid]: value});
    this.activeQ.set(qid);
    this.save$.next();
  }

  qState(qid: number): 'answered' | 'current' | 'unanswered' {
    if (this.activeQ() === qid) return 'current';
    return this.answerStr(qid) !== '' ? 'answered' : 'unanswered';
  }

  /** After grading, per-question correctness for the navigator/badges. */
  qCorrect(qid: number): boolean | null {
    const r = this.response(qid);
    return r ? r.correct : null;
  }

  private payload(): any {
    return {responses: this.flatQuestions().map((q: any) => ({question_id: q.id, answer: this.answerStr(q.id)}))};
  }

  private autosave(): void {
    if (this.readOnly()) return;
    this.saving.set(true);
    this.api.post<any>(`/backend/assessment/worksheets/${this.worksheetId()}/save`, this.payload()).subscribe({
      next: (res) => { this.savedAt.set(res?.saved_at ?? new Date().toISOString()); this.saving.set(false); },
      error: () => { this.saving.set(false); },
    });
  }

  scrollTo(qid: number): void {
    this.activeQ.set(qid);
    if (!isPlatformBrowser(this.platformId)) return;
    document.getElementById('wq-' + qid)?.scrollIntoView({behavior: 'smooth', block: 'center'});
  }

  submit(): void {
    if (this.answeredCount() === 0) { this.toast.error('Answer at least one question before submitting'); return; }
    this.submitting.set(true);
    this.api.post<any>(`/backend/assessment/worksheets/${this.worksheetId()}/submit`, this.payload()).subscribe({
      next: (res) => {
        this.data.set(res);
        this.submitting.set(false);
        this.toast.success('Worksheet submitted');
        if (!isPlatformBrowser(this.platformId)) return;
        window.scrollTo({top: 0, behavior: 'smooth'});
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not submit'); this.submitting.set(false); },
    });
  }

  exit(): void { this.done.emit(); }
}
