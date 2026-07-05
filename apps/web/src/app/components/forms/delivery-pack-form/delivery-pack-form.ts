import {Component, computed, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {ApiService} from '../../../common/service/api.service';

@Component({
  selector: 'app-delivery-pack-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoButton],
  templateUrl: './delivery-pack-form.html',
})
export class DeliveryPackForm {
  select = input<any | null>(null);
  /** Topics that don't yet have a pack — used only when creating. */
  availableTopics = input<any[]>([]);

  isEdit = signal(false);
  isLoading = signal(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  form = new FormGroup({
    topicId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    learnerNote: new FormControl(''),
    videoUrl: new FormControl(''),
    workedExamples: new FormControl(''),
    teacherGuide: new FormControl(''),
    parentWording: new FormControl(''),
  });

  readonly editTopicTitle = computed(() => this.select()?.topic ?? '');

  constructor() {
    effect(() => {
      const s = this.select();
      if (!s || !s['id']) { this.form.reset(); this.form.get('topicId')!.enable(); this.isEdit.set(false); return; }
      this.form.reset();
      this.form.patchValue({
        topicId: s['topic_id'] ?? null,
        learnerNote: s['learner_note'] ?? '',
        videoUrl: s['video_url'] ?? '',
        workedExamples: s['worked_examples'] ?? '',
        teacherGuide: s['teacher_guide'] ?? '',
        parentWording: s['parent_wording'] ?? '',
      });
      this.form.get('topicId')!.disable(); // topic is fixed on an existing pack
      this.isEdit.set(true);
    });
  }

  onSubmit(): void {
    if (this.form.get('topicId')!.invalid) {
      this.toast.error('Choose a topic');
      return;
    }
    const v = this.form.getRawValue();
    const body: any = {
      topic_id: v.topicId,
      learner_note: v.learnerNote,
      video_url: v.videoUrl,
      worked_examples: v.workedExamples,
      teacher_guide: v.teacherGuide,
      parent_wording: v.parentWording,
    };
    this.isLoading.set(true);
    const req = this.isEdit()
      ? this.api.put('/backend/curriculum/delivery-packs', {...body, id: this.select()['id']})
      : this.api.post('/backend/curriculum/delivery-packs', body);

    req.subscribe({
      next: () => {
        this.toast.success(this.isEdit() ? 'Lesson pack updated' : 'Lesson pack created (draft)');
        this.submitted.emit({success: true});
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save pack');
        this.isLoading.set(false);
      },
      complete: () => this.isLoading.set(false),
    });
  }
}
