import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip} from '../../../../../common/ui';

declare const bootstrap: any;

@Component({
  selector: 'app-gradebook',
  standalone: true,
  imports: [PageHeader, LearnoModal, DatePipe, Icon, KpiStrip],
  templateUrl: './gradebook.html',
  styleUrl: './gradebook.css',
})
export class Gradebook {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'assessments' | 'students' | 'matrix'>('assessments');
  loading = signal(false);
  overview = signal<any[]>([]);
  rollup = signal<any[]>([]);
  matrix = signal<any | null>(null);
  detail = signal<any | null>(null);

  readonly kpis = computed<KpiItem[]>(() => {
    const o = this.overview();
    const attempts = o.reduce((s, x) => s + (x.attempts || 0), 0);
    const avg = o.length ? Math.round(o.reduce((s, x) => s + (x.average || 0), 0) / o.length) : null;
    const passRate = o.length ? Math.round(o.reduce((s, x) => s + (x.pass_rate || 0), 0) / o.length) : null;
    return [
      {label: 'Assessments', value: o.length, icon: 'quiz', tone: 'primary'},
      {label: 'Total attempts', value: attempts, icon: 'assignment_turned_in', tone: 'info'},
      {label: 'Class average', value: avg === null ? '-' : avg + '%', icon: 'workspace_premium', tone: avg === null ? 'secondary' : avg >= 70 ? 'success' : avg >= 50 ? 'warning' : 'danger'},
      {label: 'Avg pass rate', value: passRate === null ? '-' : passRate + '%', icon: 'check_circle', tone: 'success'},
    ];
  });

  constructor() {
    this.loadOverview();
  }

  setMode(m: 'assessments' | 'students' | 'matrix'): void {
    this.mode.set(m);
    if (m === 'students' && !this.rollup().length) this.loadStudents();
    if (m === 'assessments' && !this.overview().length) this.loadOverview();
    if (m === 'matrix' && !this.matrix()) this.loadMatrix();
  }

  private loadMatrix(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/gradebook/matrix').subscribe({
      next: (res) => { this.matrix.set(res ?? null); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the gradebook matrix'); },
    });
  }

  /** A student's percentage for one component, or null when not attempted. */
  cellScore(row: any, columnId: number): number | null {
    const v = row?.scores?.[columnId];
    return v === undefined || v === null ? null : v;
  }

  gradeColor(grade: string | null, pct: number | null): string {
    if (!grade) return 'secondary';
    return this.scoreColor(pct);
  }

  private loadOverview(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/gradebook').subscribe({
      next: (res) => { this.overview.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the gradebook'); },
    });
  }

  private loadStudents(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/gradebook/students').subscribe({
      next: (res) => { this.rollup.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load student scores'); },
    });
  }

  openDetail(row: any): void {
    this.detail.set(null);
    this.api.get<any>(`/backend/assessment/gradebook/${row.id}`).subscribe({
      next: (res) => {
        this.detail.set(res);
        const el = document.getElementById('gradebook_detail');
        if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
      },
      error: () => this.toast.error('Could not load attempts'),
    });
  }

  scoreColor(pct: number | null): string {
    if (pct === null || pct === undefined) return 'secondary';
    if (pct >= 70) return 'success';
    if (pct >= 50) return 'warning';
    return 'danger';
  }

  exporting = signal(false);

  exportCsv(): void {
    this.exporting.set(true);
    this.api.get('/backend/export/gradebook', {responseType: 'blob', observe: 'body'}).subscribe({
      next: (blob: any) => { this.downloadBlob(blob, 'gradebook.csv'); this.exporting.set(false); },
      error: () => { this.exporting.set(false); this.toast.error('Could not export the gradebook'); },
    });
  }

  private downloadBlob(blob: Blob, filename: string): void {
    if (typeof window === 'undefined') return;
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
  }
}
