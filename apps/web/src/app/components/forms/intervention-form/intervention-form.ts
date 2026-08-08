import {Component, computed, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {ApiService} from '../../../common/service/api.service';

@Component({
  selector: 'app-intervention-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoButton],
  templateUrl: './intervention-form.html',
})
export class InterventionForm {
  select = input<any | null>(null);
  students = input<any[]>([]);
  subjects = input<any[]>([]);
  topics = input<any[]>([]);
  teachers = input<any[]>([]);

  isEdit = signal(false);
  isLoading = signal(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  form = new FormGroup({
    studentId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    subjectId: new FormControl<number | null>(null),
    topicId: new FormControl<number | null>(null),
    assignedToId: new FormControl<number | null>(null),
    reason: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    type: new FormControl(''),
    priority: new FormControl('medium', {nonNullable: true}),
    progress: new FormControl<number>(0, {nonNullable: true}),
    dueDate: new FormControl(''),
    status: new FormControl('open', {nonNullable: true}),
    outcome: new FormControl(''),
  });

  readonly subjectTopics = computed(() => {
    const sid = this.subjectId();
    return sid ? this.topics().filter((t) => t.subject_id === sid) : this.topics();
  });
  private subjectId = signal<number | null>(null);

  constructor() {
    this.form.get('subjectId')!.valueChanges.subscribe((v) => this.subjectId.set(v));

    effect(() => {
      const s = this.select();
      if (!s || !s['id']) { this.form.reset({status: 'open'}); this.form.get('studentId')!.enable(); this.isEdit.set(false); return; }
      this.form.reset({status: 'open'});
      this.form.patchValue({
        studentId: s['student_id'] ?? null,
        subjectId: s['subject_id'] ?? null,
        topicId: s['topic_id'] ?? null,
        assignedToId: s['assigned_to_id'] ?? null,
        reason: s['reason'] ?? '',
        type: s['type'] ?? '',
        priority: s['priority'] ?? 'medium',
        progress: s['progress'] ?? 0,
        dueDate: s['due_date'] ?? '',
        status: s['status'] ?? 'open',
        outcome: s['outcome'] ?? '',
      });
      this.form.get('studentId')!.disable(); // student is fixed on an existing intervention
      this.isEdit.set(true);
    });
  }

  onSubmit(): void {
    if (this.form.get('studentId')!.invalid || this.form.get('reason')!.invalid) {
      this.toast.error('A student and a reason are required');
      return;
    }
    const v = this.form.getRawValue();
    const body: any = {
      student_id: v.studentId,
      subject_id: v.subjectId,
      topic_id: v.topicId,
      assigned_to_id: v.assignedToId,
      reason: v.reason,
      type: v.type || null,
      priority: v.priority,
      progress: Number(v.progress) || 0,
      due_date: v.dueDate || null,
      status: v.status,
      outcome: v.outcome,
    };
    this.isLoading.set(true);
    const req = this.isEdit()
      ? this.api.put('/backend/school/interventions', {...body, id: this.select()['id']})
      : this.api.post('/backend/school/interventions', body);

    req.subscribe({
      next: () => {
        this.toast.success(this.isEdit() ? 'Intervention updated' : 'Intervention created');
        this.submitted.emit({success: true});
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save');
        this.isLoading.set(false);
      },
      complete: () => this.isLoading.set(false),
    });
  }
}
