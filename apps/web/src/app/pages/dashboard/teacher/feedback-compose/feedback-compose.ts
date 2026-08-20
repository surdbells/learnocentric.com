import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../common/icon/icon';
import {ApiService} from '../../../../common/service/api.service';

interface FocusArea { label: string; score: number; on: boolean; }

/**
 * Teacher structured-feedback authoring (the other half of the Feedback loop).
 * Writes a multi-part FeedbackNote (did-well / improve / common-error / tutor
 * comment / next-step + score + teacher-rated focus areas) the learner sees as
 * the Feedback breakdown. Focus-area scores are author-provided — no fabrication.
 */
@Component({
  selector: 'app-feedback-compose',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule],
  templateUrl: './feedback-compose.html',
  styleUrl: './feedback-compose.css',
})
export class FeedbackCompose {
  private api = inject(ApiService);
  private toast = inject(ToastrService);

  students = signal<{id: number; name: string}[]>([]);
  busy = signal(false);

  /** The chosen learner's submissions, so the teacher can see the work they're feeding back on. */
  submissions = signal<any[]>([]);
  selectedKey = signal<string>('');
  readonly selectedSubmission = computed<any | null>(() =>
    this.submissions().find(s => `${s.type}-${s.id}` === this.selectedKey()) ?? null);

  studentId = signal<number | null>(null);
  type = signal('correction');
  sourceType = signal('worksheet');
  sourceTitle = signal('');
  subject = signal('');
  score = signal<number | null>(null);
  strengths = signal('');
  practiceNeeded = signal('');
  commonError = signal('');
  message = signal('');
  nextStep = signal('');

  focus = signal<FocusArea[]>([
    {label: 'Accuracy', score: 70, on: true},
    {label: 'Showing Working', score: 70, on: true},
    {label: 'Question Interpretation', score: 70, on: true},
    {label: 'Problem Solving', score: 70, on: true},
  ]);

  constructor() {
    this.api.get<any>('/backend/assessment/gradebook/students').subscribe({
      next: (r) => this.students.set((r?.data ?? []).map((x: any) => ({id: x.student_id, name: x.student}))),
      error: () => this.toast.error('Could not load the learner list'),
    });
  }

  /** Choose a learner and load their submissions so the teacher can view the work. */
  selectStudent(id: number | null): void {
    this.studentId.set(id);
    this.selectedKey.set('');
    this.submissions.set([]);
    if (!id) return;
    this.api.get<any>(`/backend/assessment/submissions/by-learner?student_id=${id}`).subscribe({
      next: (r) => this.submissions.set(r?.data ?? []),
      error: () => {},
    });
  }

  /** Pick a specific submission to view + pre-fill the feedback context. */
  pickSubmission(key: string): void {
    this.selectedKey.set(key);
    const s = this.selectedSubmission();
    if (!s) return;
    this.sourceType.set(s.type);
    this.sourceTitle.set(s.title || '');
    if (s.subject) this.subject.set(s.subject);
    if (s.score != null && s.total_marks) this.score.set(Math.round(s.score / s.total_marks * 100));
  }

  setFocus(i: number, patch: Partial<FocusArea>): void {
    this.focus.set(this.focus().map((f, idx) => idx === i ? {...f, ...patch} : f));
  }

  submit(): void {
    if (!this.studentId()) { this.toast.error('Choose a learner'); return; }
    if (!this.message().trim()) { this.toast.error('A tutor comment is required'); return; }
    this.busy.set(true);
    const payload: any = {
      student_id: this.studentId(),
      type: this.type(),
      source_type: this.sourceType(),
      source_title: this.sourceTitle().trim(),
      subject: this.subject().trim(),
      message: this.message().trim(),
      strengths: this.strengths().trim(),
      practice_needed: this.practiceNeeded().trim(),
      common_error: this.commonError().trim(),
      next_step: this.nextStep().trim(),
      focus_areas: this.focus().filter(f => f.on).map(f => ({label: f.label, score: f.score})),
    };
    if (this.score() != null) payload.score = this.score();

    this.api.post<any>('/backend/assessment/feedback', payload).subscribe({
      next: () => { this.toast.success('Feedback sent'); this.reset(); this.busy.set(false); },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not send feedback'); this.busy.set(false); },
    });
  }

  private reset(): void {
    this.sourceTitle.set(''); this.subject.set(''); this.score.set(null);
    this.strengths.set(''); this.practiceNeeded.set(''); this.commonError.set('');
    this.message.set(''); this.nextStep.set('');
    this.focus.set(this.focus().map(f => ({...f, score: 70, on: true})));
  }
}
