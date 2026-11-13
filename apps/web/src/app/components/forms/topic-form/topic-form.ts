import {Component, effect, EventEmitter, inject, input, Output, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {RichEditor} from '../../../common/rich-editor/rich-editor';
import {ApiService} from '../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';

/** A stored list shown as one item per line in a textarea. */
function lines(v: unknown): string {
  return Array.isArray(v) ? v.join('\n') : String(v ?? '');
}

@Component({
  selector: 'app-topic-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoButton, RichEditor],
  templateUrl: './topic-form.html',
})
export class TopicForm {
  select = input<any | null>(null);
  subjects = input<any[]>([]);

  isEdit = signal(false);
  isLoading = signal(false);
  /** Classes to scope the topic to, or "all classes". */
  classes = signal<{ id: number; label: string }[]>([]);
  @Output() submitted = new EventEmitter<{ success: boolean }>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  form = new FormGroup({
    title: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    subjectId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    classId: new FormControl<number | null>(null),
    weekNumber: new FormControl<number | null>(null),
    objective: new FormControl(''),
    realLifeRelevance: new FormControl(''),
    competencyBuilt: new FormControl(''),
    strand: new FormControl(''),
    prerequisites: new FormControl(''),
    workplaceRelevance: new FormControl(''),
    coreTheory: new FormControl(''),
    misconceptions: new FormControl(''),   // one per line
    questionTypes: new FormControl(''),    // one per line
  });

  constructor() {
    this.api.get<any>('/backend/school/classes').subscribe({
      next: (r) => {
        const rows = Array.isArray(r) ? r : (r?.data ?? []);
        this.classes.set(rows.map((c: any) => ({id: c.id, label: c.label || c.name})).filter((c: any) => c.id && c.label));
      },
    });

    effect(() => {
      const s = this.select();
      if (!s || !s['id']) { this.form.reset(); this.isEdit.set(false); return; }
      this.form.reset();
      this.form.patchValue({
        title: s['title'] ?? '',
        subjectId: s['subject_id'] ?? null,
        classId: s['class_id'] ?? null,
        weekNumber: s['week_number'] ?? null,
        objective: s['objective'] ?? '',
        realLifeRelevance: s['real_life_relevance'] ?? '',
        competencyBuilt: s['competency_built'] ?? '',
        strand: s['strand'] ?? '',
        prerequisites: s['prerequisites'] ?? '',
        workplaceRelevance: s['workplace_relevance'] ?? '',
        coreTheory: s['core_theory'] ?? '',
        misconceptions: lines((s as any)['misconceptions']),
        questionTypes: lines((s as any)['question_types']),
      }, {emitEvent: false});
      this.isEdit.set(true);
    });
  }

  onSubmit(): void {
    if (this.form.invalid) {
      this.toast.error('A title and subject are required');
      return;
    }
    this.isLoading.set(true);
    const v = this.form.value;
    const body: any = {
      title: v.title,
      subject_id: v.subjectId,
      class_id: v.classId ?? '',
      week_number: v.weekNumber,
      objective: v.objective,
      real_life_relevance: v.realLifeRelevance,
      competency_built: v.competencyBuilt,
      strand: v.strand ?? '',
      prerequisites: v.prerequisites ?? '',
      workplace_relevance: v.workplaceRelevance ?? '',
      core_theory: v.coreTheory ?? '',
      // one per line → list
      misconceptions: v.misconceptions ?? '',
      question_types: v.questionTypes ?? '',
    };
    const req = this.isEdit()
      ? this.api.put('/backend/curriculum/topics', {...body, id: this.select()['id']})
      : this.api.post('/backend/curriculum/topics', body);

    req.subscribe({
      next: () => {
        this.toast.success(this.isEdit() ? 'Topic updated' : 'Topic created (draft)');
        this.form.reset();
        this.submitted.emit({success: true});
      },
      error: (e) => {
        this.toast.error(e?.error?.error || 'Failed to save topic');
        this.isLoading.set(false);
      },
      complete: () => this.isLoading.set(false),
    });
  }
}
