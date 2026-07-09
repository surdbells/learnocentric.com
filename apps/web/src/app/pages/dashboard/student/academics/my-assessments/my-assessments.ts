import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

@Component({
  selector: 'app-my-assessments',
  standalone: true,
  imports: [Icon, PageHeader, FormsModule, DatePipe],
  templateUrl: './my-assessments.html',
  styleUrl: './my-assessments.css',
})
export class MyAssessments {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'list' | 'take' | 'result'>('list');
  loading = signal(false);
  busy = signal(false);
  available = signal<any[]>([]);
  current = signal<any | null>(null);
  result = signal<any | null>(null);
  responses = signal<Record<number, any>>({});

  constructor() {
    this.loadAvailable();
  }

  loadAvailable(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/attempts/available').subscribe({
      next: (res) => { this.available.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your assessments'); },
    });
  }

  start(a: any): void {
    this.busy.set(true);
    this.api.post<any>('/backend/assessment/attempts', {assessment_id: a.id}).subscribe({
      next: (attempt) => {
        this.current.set(attempt);
        this.responses.set({});
        this.mode.set('take');
        this.busy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not start'); this.busy.set(false); },
    });
  }

  viewResult(attemptId: number): void {
    this.busy.set(true);
    this.api.get<any>(`/backend/assessment/attempts/${attemptId}`).subscribe({
      next: (res) => { this.result.set(res); this.mode.set('result'); this.busy.set(false); },
      error: () => { this.toast.error('Could not load result'); this.busy.set(false); },
    });
  }

  setResponse(questionId: number, value: any): void {
    this.responses.set({...this.responses(), [questionId]: value});
  }

  isChecked(questionId: number, key: string): boolean {
    const v = this.responses()[questionId];
    return Array.isArray(v) && v.includes(key);
  }

  toggleMulti(questionId: number, key: string, checked: boolean): void {
    const current = this.responses()[questionId];
    const selected: string[] = Array.isArray(current) ? [...current] : [];
    const idx = selected.indexOf(key);
    if (checked && idx < 0) selected.push(key);
    if (!checked && idx >= 0) selected.splice(idx, 1);
    this.setResponse(questionId, selected);
  }

  answered(): number {
    return Object.values(this.responses()).filter(
      (v) => v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && v.length === 0),
    ).length;
  }

  submit(): void {
    const attempt = this.current();
    if (!attempt) return;
    const answers = (attempt.questions ?? []).map((q: any) => ({question_id: q.question_id, response: this.responses()[q.question_id] ?? null}));
    this.busy.set(true);
    this.api.post<any>(`/backend/assessment/attempts/${attempt.id}/submit`, {answers}).subscribe({
      next: (res) => { this.result.set(res); this.mode.set('result'); this.busy.set(false); },
      error: (e) => { this.toast.error(e?.error?.error || 'Submit failed'); this.busy.set(false); },
    });
  }

  backToList(): void {
    this.current.set(null);
    this.result.set(null);
    this.mode.set('list');
    this.loadAvailable();
  }

  responseText(r: any): string {
    if (r === null || r === undefined || r === '') return '—';
    if (Array.isArray(r)) return r.length ? r.join(' / ') : '—';
    return String(r);
  }

  answerText(a: any): string {
    const ca = a?.correct_answer;
    if (ca === null || ca === undefined) return '';
    if (Array.isArray(ca)) return ca.join(' / ');
    if (typeof ca === 'object') return String(ca.value ?? '');
    return String(ca);
  }
}
