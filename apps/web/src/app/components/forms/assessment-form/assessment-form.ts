import {Component, computed, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {ApiService} from '../../../common/service/api.service';

@Component({
  selector: 'app-assessment-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoButton],
  templateUrl: './assessment-form.html',
})
export class AssessmentForm {
  select = input<any | null>(null);
  subjects = input<any[]>([]);
  topics = input<any[]>([]);

  isEdit = signal(false);
  isLoading = signal(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  form = new FormGroup({
    title: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    subjectId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    topicId: new FormControl<number | null>(null),
    type: new FormControl('quiz', {nonNullable: true}),
    track: new FormControl('academic', {nonNullable: true}),
    durationMinutes: new FormControl<number | null>(null),
    passMark: new FormControl(50, {nonNullable: true}),
    instructions: new FormControl(''),
  });

  /** Topics narrowed to the chosen subject. */
  readonly subjectTopics = computed(() => {
    const sid = this.subjectId();
    return sid ? this.topics().filter((t) => t.subject_id === sid) : this.topics();
  });
  private subjectId = signal<number | null>(null);

  constructor() {
    this.form.get('subjectId')!.valueChanges.subscribe((v) => this.subjectId.set(v));

    effect(() => {
      const s = this.select();
      if (!s || !s['id']) { this.form.reset({type: 'quiz', track: 'academic', passMark: 50}); this.isEdit.set(false); return; }
      this.form.reset({type: 'quiz', track: 'academic', passMark: 50});
      this.form.patchValue({
        title: s['title'] ?? '',
        subjectId: s['subject_id'] ?? null,
        topicId: s['topic_id'] ?? null,
        type: s['type'] ?? 'quiz',
        track: s['track'] ?? 'academic',
        durationMinutes: s['duration_minutes'] ?? null,
        passMark: s['pass_mark'] ?? 50,
        instructions: s['instructions'] ?? '',
      });
      this.isEdit.set(true);
    });
  }

  onSubmit(): void {
    if (this.form.get('title')!.invalid || this.form.get('subjectId')!.invalid) {
      this.toast.error('A title and subject are required');
      return;
    }
    const v = this.form.value;
    const body: any = {
      title: v.title,
      subject_id: v.subjectId,
      topic_id: v.topicId,
      type: v.type,
      track: v.track,
      duration_minutes: v.durationMinutes,
      pass_mark: v.passMark,
      instructions: v.instructions,
    };
    this.isLoading.set(true);
    const req = this.isEdit()
      ? this.api.put('/backend/assessment/assessments', {...body, id: this.select()['id']})
      : this.api.post('/backend/assessment/assessments', body);

    req.subscribe({
      next: () => {
        this.toast.success(this.isEdit() ? 'Assessment updated' : 'Assessment created (draft)');
        this.submitted.emit({success: true});
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save assessment');
        this.isLoading.set(false);
      },
      complete: () => this.isLoading.set(false),
    });
  }
}
