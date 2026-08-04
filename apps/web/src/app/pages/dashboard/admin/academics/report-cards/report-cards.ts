import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {PdfService} from '../../../../../common/service/pdf-service';
import {Icon} from '../../../../../common/icon/icon';

@Component({
  selector: 'app-report-cards',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, FormsModule],
  templateUrl: './report-cards.html',
  styleUrl: './report-cards.css',
})
export class ReportCards {
  private readonly api = inject(ApiService);
  private readonly pdf = inject(PdfService);
  private readonly toast = inject(ToastrService);

  learners = signal<any[]>([]);
  selectedId = signal<number | null>(null);
  loading = signal(false);
  downloading = signal(false);
  card = signal<any | null>(null);

  readonly gradeTone = (g: string | null): string => {
    if (!g) return 'secondary';
    const c = g.charAt(0).toUpperCase();
    return c === 'A' ? 'success' : c === 'B' ? 'primary' : c === 'C' ? 'info' : c === 'D' ? 'warning' : 'danger';
  };

  constructor() {
    this.api.get<any>('/backend/assessment/gradebook/students').subscribe({
      next: (r) => this.learners.set((r?.data ?? []).map((x: any) => ({id: x.student_id, name: x.student}))),
      error: () => this.toast.error('Could not load the learner list'),
    });
  }

  load(): void {
    const id = this.selectedId();
    if (!id) { this.card.set(null); return; }
    this.loading.set(true);
    this.api.get<any>(`/backend/analytics/report-card/${id}`).subscribe({
      next: (r) => { this.card.set(r); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the report card'); },
    });
  }

  async downloadPdf(): Promise<void> {
    const c = this.card();
    if (!c) return;
    this.downloading.set(true);
    try {
      const who = String(c.student?.name ?? 'student').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
      await this.pdf.generateHtmlPdf(`report-card-${who}.pdf`, 'reportCardArea');
    } catch {
      this.toast.error('Could not generate the PDF');
    } finally {
      this.downloading.set(false);
    }
  }

  pct(v: number | null): string { return v === null || v === undefined ? '—' : v + '%'; }
}
