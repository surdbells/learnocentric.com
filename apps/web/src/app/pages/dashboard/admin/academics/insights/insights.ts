import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {RichEditor} from '../../../../../common/rich-editor/rich-editor';
import {RichText} from '../../../../../common/rich-editor/rich-text';

const TYPE_COLOR: Record<string, string> = {praise: 'success', correction: 'warning', reteach: 'info', general: 'secondary'};

@Component({
  selector: 'app-insights',
  standalone: true,
  imports: [RichText, RichEditor, PageHeader, ReactiveFormsModule, DatePipe],
  templateUrl: './insights.html',
  styleUrl: './insights.css',
})
export class Insights {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'misconceptions' | 'feedback'>('misconceptions');
  loading = signal(false);
  busy = signal(false);
  insights = signal<any[]>([]);
  students = signal<any[]>([]);
  topics = signal<any[]>([]);
  sent = signal<any[]>([]);

  readonly types = ['praise', 'correction', 'reteach', 'general'];

  form = new FormGroup({
    studentId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    type: new FormControl('correction', {nonNullable: true}),
    topicId: new FormControl<number | null>(null),
    message: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
  });

  constructor() {
    this.loadInsights();
    this.api.get<any>('/backend/school/students').subscribe({next: (r) => this.students.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? []))});
  }

  setMode(m: 'misconceptions' | 'feedback'): void {
    this.mode.set(m);
    if (m === 'feedback' && !this.sent().length) this.loadSent();
  }

  private loadInsights(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/insights').subscribe({
      next: (res) => { this.insights.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load insights'); },
    });
  }

  private loadSent(): void {
    this.api.get<any>('/backend/assessment/feedback').subscribe({
      next: (res) => this.sent.set(res?.data ?? res ?? []),
    });
  }

  prefillFeedback(insight: any): void {
    this.form.patchValue({type: 'reteach', topicId: insight.topic_id, message: `On "${insight.stem}" — let's revisit this. `});
    this.mode.set('feedback');
    if (!this.sent().length) this.loadSent();
  }

  send(): void {
    if (this.form.invalid) { this.toast.error('Pick a student and write a message'); return; }
    const v = this.form.value;
    this.busy.set(true);
    this.api.post<any>('/backend/assessment/feedback', {
      student_id: v.studentId, type: v.type, topic_id: v.topicId, message: v.message,
    }).subscribe({
      next: () => {
        this.toast.success('Feedback sent');
        this.form.reset({type: 'correction'});
        this.loadSent();
        this.busy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Send failed'); this.busy.set(false); },
    });
  }

  missColor(rate: number): string {
    if (rate >= 60) return 'danger';
    if (rate >= 30) return 'warning';
    return 'secondary';
  }

  typeColor(t: string): string { return TYPE_COLOR[t] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
