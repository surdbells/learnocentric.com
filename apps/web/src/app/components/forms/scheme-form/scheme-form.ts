import {Component, computed, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {ApiService} from '../../../common/service/api.service';

@Component({
  selector: 'app-scheme-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoButton],
  templateUrl: './scheme-form.html',
})
export class SchemeForm {
  select = input<any | null>(null);
  classes = input<any[]>([]);
  subjects = input<any[]>([]);
  terms = input<any[]>([]);
  topics = input<any[]>([]);
  teachers = input<any[]>([]);

  isEdit = signal(false);
  isLoading = signal(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  form = new FormGroup({
    classId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    subjectId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    termId: new FormControl<number | null>(null),
    weekNumber: new FormControl<number | null>(null, {validators: [Validators.required]}),
    topicId: new FormControl<number | null>(null),
    assignedTeacherId: new FormControl<number | null>(null),
    objective: new FormControl(''),
    status: new FormControl('draft', {nonNullable: true}),
  });

  /** Topics filtered to the chosen subject. */
  readonly subjectTopics = computed(() => {
    const sid = this.subjectId();
    return sid ? this.topics().filter((t) => t.subject_id === sid) : this.topics();
  });
  private subjectId = signal<number | null>(null);

  constructor() {
    this.form.get('subjectId')!.valueChanges.subscribe((v) => this.subjectId.set(v));

    effect(() => {
      const s = this.select();
      if (!s || !s['id']) { this.form.reset({status: 'draft'}); this.isEdit.set(false); return; }
      this.form.reset({status: 'draft'});
      this.form.patchValue({
        classId: s['class_id'] ?? null,
        subjectId: s['subject_id'] ?? null,
        termId: s['term_id'] ?? null,
        weekNumber: s['week_number'] ?? null,
        topicId: s['topic_id'] ?? null,
        assignedTeacherId: s['assigned_teacher_id'] ?? null,
        objective: s['objective'] ?? '',
        status: s['status'] ?? 'draft',
      });
      this.isEdit.set(true);
    });
  }

  onSubmit(): void {
    if (this.form.get('classId')!.invalid || this.form.get('subjectId')!.invalid || this.form.get('weekNumber')!.invalid) {
      this.toast.error('Class, subject and week are required');
      return;
    }
    const v = this.form.value;
    const body: any = {
      class_id: v.classId,
      subject_id: v.subjectId,
      term_id: v.termId,
      week_number: v.weekNumber,
      topic_id: v.topicId,
      assigned_teacher_id: v.assignedTeacherId,
      objective: v.objective,
      status: v.status,
    };
    this.isLoading.set(true);
    const req = this.isEdit()
      ? this.api.put('/backend/school/scheme-of-work', {...body, id: this.select()['id']})
      : this.api.post('/backend/school/scheme-of-work', body);

    req.subscribe({
      next: () => {
        this.toast.success(this.isEdit() ? 'Week updated' : 'Week added');
        this.submitted.emit({success: true});
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save week');
        this.isLoading.set(false);
      },
      complete: () => this.isLoading.set(false),
    });
  }
}
