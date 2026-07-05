import {Component, computed, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {ApiService} from '../../../common/service/api.service';

@Component({
  selector: 'app-live-class-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoButton],
  templateUrl: './live-class-form.html',
})
export class LiveClassForm {
  select = input<any | null>(null);
  subjects = input<any[]>([]);
  classes = input<any[]>([]);
  topics = input<any[]>([]);

  isEdit = signal(false);
  isLoading = signal(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  form = new FormGroup({
    title: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    subjectId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    classId: new FormControl<number | null>(null),
    topicId: new FormControl<number | null>(null),
    scheduledAt: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    durationMinutes: new FormControl(45, {nonNullable: true}),
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
      if (!s || !s['id']) { this.form.reset({durationMinutes: 45}); this.isEdit.set(false); return; }
      this.form.reset({durationMinutes: 45});
      this.form.patchValue({
        title: s['title'] ?? '',
        subjectId: s['subject_id'] ?? null,
        classId: s['class_id'] ?? null,
        topicId: s['topic_id'] ?? null,
        scheduledAt: this.toLocal(s['scheduled_at']),
        durationMinutes: s['duration_minutes'] ?? 45,
      });
      this.isEdit.set(true);
    });
  }

  /** ISO → "YYYY-MM-DDTHH:mm" for a datetime-local input. */
  private toLocal(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  onSubmit(): void {
    if (this.form.get('title')!.invalid || this.form.get('subjectId')!.invalid || this.form.get('scheduledAt')!.invalid) {
      this.toast.error('A title, subject and start time are required');
      return;
    }
    const v = this.form.value;
    const body: any = {
      title: v.title,
      subject_id: v.subjectId,
      class_id: v.classId,
      topic_id: v.topicId,
      scheduled_at: (v.scheduledAt || '').replace('T', ' '),
      duration_minutes: v.durationMinutes,
    };
    this.isLoading.set(true);
    const req = this.isEdit()
      ? this.api.put('/backend/live-classes', {...body, id: this.select()['id']})
      : this.api.post('/backend/live-classes', body);

    req.subscribe({
      next: () => {
        this.toast.success(this.isEdit() ? 'Class updated' : 'Class scheduled');
        this.submitted.emit({success: true});
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save class');
        this.isLoading.set(false);
      },
      complete: () => this.isLoading.set(false),
    });
  }
}
