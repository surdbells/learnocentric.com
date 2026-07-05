import {Component, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormArray, FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {ApiService} from '../../../common/service/api.service';

const LETTERS = 'abcdefghij';

@Component({
  selector: 'app-question-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoButton],
  templateUrl: './question-form.html',
})
export class QuestionForm {
  select = input<any | null>(null);
  topics = input<any[]>([]);

  isEdit = signal(false);
  isLoading = signal(false);
  type = signal('mcq');
  @Output() submitted = new EventEmitter<{ success: boolean }>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  form = new FormGroup({
    topicId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    type: new FormControl('mcq', {nonNullable: true}),
    track: new FormControl('academic', {nonNullable: true}),
    difficulty: new FormControl('medium', {nonNullable: true}),
    marks: new FormControl(1, {nonNullable: true}),
    stem: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    explanation: new FormControl(''),
    options: new FormArray<FormGroup>([]),
    correctIndex: new FormControl<number | null>(null),
    tfAnswer: new FormControl('true', {nonNullable: true}),
    shortAnswers: new FormControl('', {nonNullable: true}),
    numValue: new FormControl<number | null>(null),
    numTolerance: new FormControl<number>(0, {nonNullable: true}),
  });

  get options(): FormArray<FormGroup> {
    return this.form.get('options') as FormArray<FormGroup>;
  }

  get correctIndexControl(): FormControl {
    return this.form.get('correctIndex') as FormControl;
  }

  constructor() {
    this.form.get('type')!.valueChanges.subscribe((t) => {
      this.type.set(t ?? 'mcq');
      if (t === 'mcq' && this.options.length === 0) this.seedOptions();
    });

    effect(() => {
      const s = this.select();
      if (!s || !s['id']) { this.reset(); return; }
      this.patchFrom(s);
    });
  }

  private reset(): void {
    this.form.reset({type: 'mcq', track: 'academic', difficulty: 'medium', marks: 1, tfAnswer: 'true', numTolerance: 0});
    this.options.clear();
    this.seedOptions();
    this.type.set('mcq');
    this.isEdit.set(false);
  }

  private seedOptions(): void {
    this.options.clear();
    for (let i = 0; i < 4; i++) this.options.push(new FormGroup({text: new FormControl('', {nonNullable: true})}));
  }

  private patchFrom(s: any): void {
    this.options.clear();
    this.form.patchValue({
      topicId: s['topic_id'] ?? null,
      type: s['type'] ?? 'mcq',
      track: s['track'] ?? 'academic',
      difficulty: s['difficulty'] ?? 'medium',
      marks: s['marks'] ?? 1,
      stem: s['stem'] ?? '',
      explanation: s['explanation'] ?? '',
    }, {emitEvent: false});
    this.type.set(s['type'] ?? 'mcq');

    const ans = s['correct_answer'];
    if (s['type'] === 'mcq') {
      const opts: any[] = Array.isArray(s['options']) ? s['options'] : [];
      opts.forEach((o) => this.options.push(new FormGroup({text: new FormControl(o?.text ?? '', {nonNullable: true})})));
      while (this.options.length < 2) this.options.push(new FormGroup({text: new FormControl('', {nonNullable: true})}));
      const idx = opts.findIndex((o) => o?.key === ans);
      this.form.get('correctIndex')!.setValue(idx >= 0 ? idx : null, {emitEvent: false});
    } else if (s['type'] === 'true_false') {
      this.form.get('tfAnswer')!.setValue(ans === false || ans === 'false' ? 'false' : 'true', {emitEvent: false});
    } else if (s['type'] === 'short') {
      const list = Array.isArray(ans) ? ans : (ans ? [ans] : []);
      this.form.get('shortAnswers')!.setValue(list.join('\n'), {emitEvent: false});
    } else if (s['type'] === 'numeric') {
      const val = ans && typeof ans === 'object' ? ans.value : ans;
      const tol = ans && typeof ans === 'object' ? (ans.tolerance ?? 0) : 0;
      this.form.get('numValue')!.setValue(val ?? null, {emitEvent: false});
      this.form.get('numTolerance')!.setValue(tol, {emitEvent: false});
    }
    this.isEdit.set(true);
  }

  addOption(): void {
    if (this.options.length >= LETTERS.length) return;
    this.options.push(new FormGroup({text: new FormControl('', {nonNullable: true})}));
  }

  removeOption(i: number): void {
    this.options.removeAt(i);
    const ci = this.form.get('correctIndex')!.value;
    if (ci === i) this.form.get('correctIndex')!.setValue(null);
    else if (ci !== null && ci > i) this.form.get('correctIndex')!.setValue(ci - 1);
  }

  letter(i: number): string { return LETTERS[i]?.toUpperCase() ?? String(i + 1); }

  private buildAnswer(v: any): { options: any; correct: any } {
    switch (this.type()) {
      case 'mcq': {
        const opts = this.options.controls
          .map((c, i) => ({key: LETTERS[i], text: (c.get('text')!.value ?? '').trim()}))
          .filter((o) => o.text !== '');
        const idx = v.correctIndex;
        const correct = idx !== null && idx >= 0 && idx < opts.length ? LETTERS[idx] : null;
        return {options: opts, correct};
      }
      case 'true_false':
        return {options: null, correct: v.tfAnswer};
      case 'short':
        return {options: null, correct: String(v.shortAnswers ?? '').split(/[\n,]/).map((s: string) => s.trim()).filter(Boolean)};
      case 'numeric':
        return {options: null, correct: {value: v.numValue, tolerance: v.numTolerance ?? 0}};
      default:
        return {options: null, correct: null};
    }
  }

  onSubmit(): void {
    if (this.form.get('topicId')!.invalid || this.form.get('stem')!.invalid) {
      this.toast.error('A topic and a question stem are required');
      return;
    }
    const v = this.form.getRawValue();
    const {options, correct} = this.buildAnswer(v);
    const body: any = {
      topic_id: v.topicId,
      type: v.type,
      track: v.track,
      difficulty: v.difficulty,
      marks: v.marks,
      stem: v.stem,
      explanation: v.explanation,
      options,
      correct_answer: correct,
    };
    this.isLoading.set(true);
    const req = this.isEdit()
      ? this.api.put('/backend/assessment/questions', {...body, id: this.select()['id']})
      : this.api.post('/backend/assessment/questions', body);

    req.subscribe({
      next: () => {
        this.toast.success(this.isEdit() ? 'Question updated' : 'Question created (draft)');
        this.submitted.emit({success: true});
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save question');
        this.isLoading.set(false);
      },
      complete: () => this.isLoading.set(false),
    });
  }
}
