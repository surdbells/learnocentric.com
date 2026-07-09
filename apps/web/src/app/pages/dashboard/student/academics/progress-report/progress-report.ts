import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {PdfService} from '../../../../../common/service/pdf-service';
import {Icon} from '../../../../../common/icon/icon';
import {RichText} from '../../../../../common/rich-editor/rich-text';

const RATING_COLOR: Record<string, string> = {emerging: 'secondary', developing: 'info', proficient: 'primary', mastery: 'success'};

@Component({
  selector: 'app-progress-report',
  standalone: true,
  imports: [RichText, Icon, PageHeader, DatePipe],
  templateUrl: './progress-report.html',
  styleUrl: './progress-report.css',
})
export class ProgressReport {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);
  private readonly pdf = inject(PdfService);

  loading = signal(true);
  report = signal<any | null>(null);
  children = signal<any[]>([]);
  selectedChild = signal<number | null>(null);
  isParent = signal(false);
  downloading = signal(false);

  constructor() {
    const user = this.auth.getAuthSession()?.user;
    if (user?.role === 'parent') {
      this.isParent.set(true);
      this.api.get<any>('/backend/analytics/children').subscribe({
        next: (res) => {
          this.children.set(res?.data ?? []);
          const first = res?.data?.[0]?.student_id;
          if (first) { this.selectChild(first); } else { this.loading.set(false); }
        },
        error: () => this.loading.set(false),
      });
    } else if (user?.id) {
      this.loadReport(Number(user.id));
    }
  }

  selectChild(id: number): void {
    this.selectedChild.set(id);
    this.loadReport(id);
  }

  private loadReport(studentId: number): void {
    this.loading.set(true);
    this.api.get<any>(`/backend/analytics/student/${studentId}`).subscribe({
      next: (res) => { this.report.set(res); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the report'); },
    });
  }

  scoreColor(pct: number | null): string {
    if (pct === null || pct === undefined) return 'secondary';
    if (pct >= 70) return 'success';
    if (pct >= 50) return 'warning';
    return 'danger';
  }

  async downloadPdf(): Promise<void> {
    const r = this.report();
    if (!r) return;
    this.downloading.set(true);
    try {
      const who = String(r.student?.name ?? 'student').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
      await this.pdf.generateHtmlPdf(`progress-report-${who}.pdf`, 'progressReportArea');
    } catch {
      this.toast.error('Could not generate the PDF');
    } finally {
      this.downloading.set(false);
    }
  }

  ratingColor(r: string | null): string { return r ? (RATING_COLOR[r] ?? 'secondary') : 'secondary'; }
  pct(v: number | null): string { return v === null || v === undefined ? '—' : v + '%'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  /** Strip HTML tags (feedback may be rich text) and collapse whitespace for plain-text share. */
  private plain(v: string | null | undefined): string {
    if (!v) return '';
    return v.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
  }

  /** Build a plain-text, WhatsApp-friendly summary of the current report. */
  private buildSummary(): string {
    const r = this.report();
    if (!r) return '';
    const fb = r.feedback ?? {};
    const lines: string[] = [];
    lines.push(`Progress report — ${r.student?.name ?? 'Student'}`);
    lines.push(`Quiz average: ${this.pct(r.academic?.average ?? null)}`);
    lines.push(`Worksheet average: ${this.pct(r.worksheets?.average ?? null)}`);
    lines.push(`Live classes joined: ${r.attendance?.joined ?? 0}/${r.attendance?.offered ?? 0}`);
    const strengths = this.plain(fb.strengths);
    if (strengths) lines.push(`Strengths: ${strengths}`);
    const practice = this.plain(fb.practice_needed);
    if (practice) lines.push(`Practice needed: ${practice}`);
    const portfolio = r.competency?.entries?.length
      ? `${r.competency.entries.length} portfolio entr${r.competency.entries.length === 1 ? 'y' : 'ies'}`
      : 'No portfolio evidence yet';
    lines.push(`Portfolio: ${portfolio}`);
    const comment = this.plain(fb.teacher_comment);
    if (comment) lines.push(`Teacher comment: ${comment}`);
    const support = this.plain(fb.parent_support_suggestion);
    if (support) lines.push(`How to help at home: ${support}`);
    return lines.join('\n');
  }

  /** Open WhatsApp (or the native share sheet) with a plain-text report summary. */
  async shareWhatsApp(): Promise<void> {
    const text = this.buildSummary();
    if (!text) return;
    const nav = navigator as any;
    if (typeof nav.share === 'function') {
      try {
        await nav.share({ title: 'Progress report', text });
        return;
      } catch {
        // User dismissed or share unsupported — fall through to WhatsApp link.
      }
    }
    window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
  }

  /** Copy the plain-text summary to the clipboard. */
  async copySummary(): Promise<void> {
    const text = this.buildSummary();
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
      this.toast.success('Summary copied to clipboard');
    } catch {
      this.toast.error('Could not copy the summary');
    }
  }
}
