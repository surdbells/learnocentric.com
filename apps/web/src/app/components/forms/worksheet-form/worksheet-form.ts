import {Component, computed, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {FileUpload, UploadedFile} from '../../../common/file-upload/file-upload';
import {ApiService} from '../../../common/service/api.service';

@Component({
  selector: 'app-worksheet-form',
  standalone: true,
  imports: [ReactiveFormsModule, FormsModule, LearnoInput, LearnoButton, FileUpload],
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

  form = new FormGroup({
    title: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    topicId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    track: new FormControl('academic', {nonNullable: true}),
    totalMarks: new FormControl(10, {nonNullable: true}),
    dueDate: new FormControl(''),
    instructions: new FormControl(''),
    attachmentUrl: new FormControl(''),
  });

  /** Subject filter for the topic list — for teachers who teach more than one subject. */
  readonly subjectFilter = signal<string>('');
  readonly subjectOptions = computed<string[]>(() =>
    [...new Set(this.topics().map(t => t.subject).filter(Boolean))] as string[]);
  readonly topicList = computed(() => {
    const s = this.subjectFilter();
    return s ? this.topics().filter(t => t.subject === s) : this.topics();
  });

  onFileUploaded(file: UploadedFile): void { this.form.get('attachmentUrl')!.setValue(file.url); }
  onFileCleared(): void { this.form.get('attachmentUrl')!.setValue(''); }

  constructor() {
    effect(() => {
      const s = this.select();
      if (!s || !s['id']) { this.form.reset({track: 'academic', totalMarks: 10}); this.isEdit.set(false); return; }
      this.form.reset({track: 'academic', totalMarks: 10});
      this.form.patchValue({
        title: s['title'] ?? '',
        topicId: s['topic_id'] ?? null,
        track: s['track'] ?? 'academic',
        totalMarks: s['total_marks'] ?? 10,
        dueDate: s['due_date'] ?? '',
        instructions: s['instructions'] ?? '',
        attachmentUrl: s['attachment_url'] ?? '',
      });
      this.isEdit.set(true);
    });
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
      total_marks: v.totalMarks,
      due_date: v.dueDate || null,
      instructions: v.instructions,
      attachment_url: v.attachmentUrl || null,
    };
    this.isLoading.set(true);
    const req = this.isEdit()
      ? this.api.put('/backend/assessment/worksheets', {...body, id: this.select()['id']})
      : this.api.post('/backend/assessment/worksheets', body);

    req.subscribe({
      next: () => {
        this.toast.success(this.isEdit() ? 'Worksheet updated' : 'Worksheet created (draft)');
        this.submitted.emit({success: true});
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save worksheet');
        this.isLoading.set(false);
      },
      complete: () => this.isLoading.set(false),
    });
  }
}
