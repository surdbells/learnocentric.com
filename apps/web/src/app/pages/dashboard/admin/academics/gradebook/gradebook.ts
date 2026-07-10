import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

declare const bootstrap: any;

@Component({
  selector: 'app-gradebook',
  standalone: true,
  imports: [PageHeader, LearnoModal, DatePipe, Icon],
  templateUrl: './gradebook.html',
  styleUrl: './gradebook.css',
})
export class Gradebook {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  mode = signal<'assessments' | 'students'>('assessments');
  loading = signal(false);
  overview = signal<any[]>([]);
  rollup = signal<any[]>([]);
  detail = signal<any | null>(null);

  constructor() {
    this.loadOverview();
  }

  setMode(m: 'assessments' | 'students'): void {
    this.mode.set(m);
    if (m === 'students' && !this.rollup().length) this.loadStudents();
    if (m === 'assessments' && !this.overview().length) this.loadOverview();
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
