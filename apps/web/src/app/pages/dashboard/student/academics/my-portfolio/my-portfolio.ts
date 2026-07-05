import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {FileUpload, UploadedFile} from '../../../../../common/file-upload/file-upload';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

const RATING_COLOR: Record<string, string> = {emerging: 'secondary', developing: 'info', proficient: 'primary', mastery: 'success'};

@Component({
  selector: 'app-my-portfolio',
  standalone: true,
  imports: [Icon, PageHeader, ReactiveFormsModule, DatePipe, FileUpload],
  templateUrl: './my-portfolio.html',
  styleUrl: './my-portfolio.css',
})
export class MyPortfolio {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'list' | 'add'>('list');
  loading = signal(false);
  busy = signal(false);
  entries = signal<any[]>([]);
  topics = signal<any[]>([]);

  form = new FormGroup({
    topicId: new FormControl<number | null>(null, {validators: [Validators.required]}),
    title: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    description: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    evidenceUrl: new FormControl(''),
  });

  constructor() {
    this.load();
    this.api.get<any>('/backend/assessment/portfolio/topics').subscribe({next: (r) => this.topics.set(r?.data ?? [])});
  }

  onEvidenceUploaded(file: UploadedFile): void { this.form.get('evidenceUrl')!.setValue(file.url); }
  onEvidenceCleared(): void { this.form.get('evidenceUrl')!.setValue(''); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/portfolio/mine').subscribe({
      next: (res) => { this.entries.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your portfolio'); },
    });
  }

  openAdd(): void {
    this.form.reset();
    this.mode.set('add');
  }

  submit(): void {
    if (this.form.invalid) { this.toast.error('A topic, title and description are required'); return; }
    const v = this.form.value;
    this.busy.set(true);
    this.api.post<any>('/backend/assessment/portfolio', {
      topic_id: v.topicId, title: v.title, description: v.description, evidence_url: v.evidenceUrl,
    }).subscribe({
      next: () => { this.toast.success('Evidence submitted'); this.busy.set(false); this.backToList(); },
      error: (e) => { this.toast.error(e?.error?.error || 'Submit failed'); this.busy.set(false); },
    });
  }

  backToList(): void {
    this.mode.set('list');
    this.load();
  }

  ratingColor(r: string | null): string { return r ? (RATING_COLOR[r] ?? 'secondary') : 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
