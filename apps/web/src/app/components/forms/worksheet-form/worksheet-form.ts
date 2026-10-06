import {Component, computed, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {FileUpload, UploadedFile} from '../../../common/file-upload/file-upload';
import {Icon} from '../../../common/icon/icon';
import {ApiService} from '../../../common/service/api.service';

/** One question in the builder. `options` is newline-separated text, split for mcq on save. */
interface QItem {
  prompt: string;
  type: string;
  marks: number;
  correctAnswer: string;
  options: string;
}
interface QSection {
  label: string;
  questions: QItem[];
}

@Component({
  selector: 'app-worksheet-form',
  standalone: true,
  imports: [ReactiveFormsModule, FormsModule, LearnoInput, LearnoButton, FileUpload, Icon],
  templateUrl: './worksheet-form.html',
})
export class WorksheetForm {
  select = input<any | null>(null);
  topics = input<any[]>([]);

  isEdit = signal(false);
  isLoading = signal(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  /** Question types supported by the backend (WorksheetQuestion::TYPES). */
  readonly questionTypes = [
    {value: 'numeric', label: 'Numeric'},
    {value: 'short', label: 'Short answer'},
    {value: 'true_false', label: 'True / False'},
    {value: 'mcq', label: 'Multiple choice'},
    {value: 'free_response', label: 'Free response'},
  ];
  /** Types that are auto-graded and so carry a correct answer. */
  private readonly autoTypes = ['numeric', 'short', 'true_false', 'mcq'];

  form = new FormGroup({
    title: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    topicId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    track: new FormControl('academic', {nonNullable: true}),
    responseMode: new FormControl('solver', {nonNullable: true}),
    totalMarks: new FormControl(10, {nonNullable: true}),
    dueDate: new FormControl(''),
    instructions: new FormControl(''),
    workedExample: new FormControl(''),
    attachmentUrl: new FormControl(''),
  });

  /** Signal mirror of the response-mode control, to toggle template-only fields in the view. */
  readonly responseMode = signal<string>('solver');

  /** Section → question builder state. */
  readonly sections = signal<QSection[]>([]);
  /** True once the builder reflects an authoritative state (new worksheet, or existing questions loaded). */
  readonly questionsReady = signal<boolean>(false);

  readonly hasQuestions = computed<boolean>(() => this.sections().some(s => s.questions.length > 0));

  /** Running total of marks (method, so it reflects in-place edits each change-detection). */
  marksTotal(): number {
    return this.sections().reduce((sum, s) => sum + s.questions.reduce((a, q) => a + (Number(q.marks) || 0), 0), 0);
  }

  /** Subject filter for the topic list, for teachers who teach more than one subject. */
  readonly subjectFilter = signal<string>('');
  readonly subjectOptions = computed<string[]>(() =>
    [...new Set(this.topics().map(t => t.subject).filter(Boolean))] as string[]);
  readonly topicList = computed(() => {
    const s = this.subjectFilter();
    return s ? this.topics().filter(t => t.subject === s) : this.topics();
  });

  onFileUploaded(file: UploadedFile): void { this.form.get('attachmentUrl')!.setValue(file.url); }
  onFileCleared(): void { this.form.get('attachmentUrl')!.setValue(''); }

  isAuto(type: string): boolean { return this.autoTypes.includes(type); }

  constructor() {
    // Keep the responseMode signal in sync with the control for the view toggle.
    this.form.get('responseMode')!.valueChanges.subscribe(v => this.responseMode.set(v ?? 'solver'));

    effect(() => {
      const s = this.select();
      if (!s || !s['id']) {
        this.form.reset({track: 'academic', responseMode: 'solver', totalMarks: 10});
        this.responseMode.set('solver');
        this.sections.set([]);
        this.questionsReady.set(true); // new worksheet: builder starts empty and authoritative
        this.isEdit.set(false);
        return;
      }
      this.form.reset({track: 'academic', responseMode: 'solver', totalMarks: 10});
      this.form.patchValue({
        title: s['title'] ?? '',
        topicId: s['topic_id'] ?? null,
        track: s['track'] ?? 'academic',
        responseMode: s['response_mode'] ?? 'solver',
        totalMarks: s['total_marks'] ?? 10,
        dueDate: s['due_date'] ?? '',
        instructions: s['instructions'] ?? '',
        workedExample: s['worked_example'] ?? '',
        attachmentUrl: s['attachment_url'] ?? '',
      });
      this.responseMode.set(s['response_mode'] ?? 'solver');
      this.isEdit.set(true);
      this.loadQuestions(s['id']);
    });
  }

  /** Load existing questions (with answers) and group them into sections for editing. */
  private loadQuestions(id: number): void {
    this.sections.set([]);
    this.questionsReady.set(false);
    this.api.get<any>(`/backend/assessment/worksheets/${id}/questions`).subscribe({
      next: (res) => {
        const rows: any[] = res?.data ?? [];
        const byLabel = new Map<string, QSection>();
        const order: string[] = [];
        for (const q of rows) {
          const label = (q.section_label ?? '').toString();
          if (!byLabel.has(label)) { byLabel.set(label, {label, questions: []}); order.push(label); }
          byLabel.get(label)!.questions.push({
            prompt: q.prompt ?? '',
            type: q.type ?? 'numeric',
            marks: q.marks ?? 1,
            correctAnswer: q.correct_answer ?? '',
            options: Array.isArray(q.options) ? q.options.join('\n') : '',
          });
        }
        this.sections.set(order.map(l => byLabel.get(l)!));
        this.questionsReady.set(true);
      },
      // On failure we leave the builder empty and NOT ready, so submit won't wipe existing questions.
      error: () => this.questionsReady.set(false),
    });
  }

  // --- Builder mutations ---
  addSection(label = ''): void { this.sections.update(a => [...a, {label, questions: []}]); }
  removeSection(i: number): void { this.sections.update(a => a.filter((_, idx) => idx !== i)); }
  addQuestion(si: number): void {
    this.sections.update(a => a.map((s, idx) => idx === si
      ? {...s, questions: [...s.questions, {prompt: '', type: 'numeric', marks: 1, correctAnswer: '', options: ''}]}
      : s));
  }
  removeQuestion(si: number, qi: number): void {
    this.sections.update(a => a.map((s, idx) => idx === si
      ? {...s, questions: s.questions.filter((_, j) => j !== qi)}
      : s));
  }
  /** Scaffold the standard worksheet-template sections. */
  addStandardSections(): void {
    this.sections.set([
      {label: 'A. Complete the guided example', questions: []},
      {label: 'B. Your turn', questions: []},
      {label: 'C. Apply it', questions: []},
    ]);
  }

  /** Flatten the builder into the setQuestions payload, dropping blank prompts. */
  private questionsPayload(): any[] {
    const out: any[] = [];
    for (const s of this.sections()) {
      for (const q of s.questions) {
        const prompt = (q.prompt ?? '').trim();
        if (!prompt) continue;
        out.push({
          prompt,
          section_label: (s.label ?? '').trim() || null,
          type: q.type,
          marks: Number(q.marks) || 1,
          correct_answer: this.isAuto(q.type) ? (q.correctAnswer ?? '').trim() : '',
          options: q.type === 'mcq'
            ? (q.options ?? '').split('\n').map(o => o.trim()).filter(Boolean)
            : null,
        });
      }
    }
    return out;
  }

  onSubmit(): void {
    if (this.form.get('title')!.invalid || this.form.get('topicId')!.invalid) {
      this.toast.error('A title and topic are required');
      return;
    }
    const v = this.form.value;
    const body: any = {
      title: v.title,
      topic_id: v.topicId,
      track: v.track,
      response_mode: v.responseMode,
      total_marks: v.totalMarks,
      due_date: v.dueDate || null,
      instructions: v.instructions,
      worked_example: v.workedExample,
      attachment_url: v.attachmentUrl || null,
    };
    const payload = this.questionsPayload();
    // Save questions when the builder is authoritative (so clearing all is honoured) or the user added some.
    const saveQuestions = this.questionsReady() || payload.length > 0;

    this.isLoading.set(true);
    const save$ = this.isEdit()
      ? this.api.put<any>('/backend/assessment/worksheets', {...body, id: this.select()['id']})
      : this.api.post<any>('/backend/assessment/worksheets', body);

    save$.subscribe({
      next: (res) => {
        const id = this.isEdit() ? this.select()['id'] : res?.id;
        if (saveQuestions && id) {
          this.api.post(`/backend/assessment/worksheets/${id}/questions`, {questions: payload}).subscribe({
            next: () => this.finishOk(),
            error: (e) => { this.toast.error(e?.error?.error || 'Worksheet saved, but its questions failed to save'); this.isLoading.set(false); },
          });
        } else {
          this.finishOk();
        }
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save worksheet');
        this.isLoading.set(false);
      },
    });
  }

  private finishOk(): void {
    this.toast.success(this.isEdit() ? 'Worksheet updated' : 'Worksheet created (draft)');
    this.isLoading.set(false);
    this.submitted.emit({success: true});
  }
}
