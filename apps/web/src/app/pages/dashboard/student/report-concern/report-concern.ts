import {Component, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {Router} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';

const CATEGORIES = [
  {value: 'welfare', label: 'Welfare'},
  {value: 'bullying', label: 'Bullying'},
  {value: 'abuse', label: 'Abuse'},
  {value: 'attendance', label: 'Attendance'},
  {value: 'mental_health', label: 'Mental health'},
  {value: 'other', label: 'Something else'},
];

/**
 * Lets a learner privately report a safeguarding concern. The report is sent to
 * the designated safeguarding leads; the learner only ever sees a confirmation
 * reference, never the managed case (spec §15/§20).
 */
@Component({
  selector: 'app-report-concern',
  standalone: true,
  imports: [Icon, PageHeader, FormsModule],
  templateUrl: './report-concern.html',
  styleUrl: './report-concern.css',
})
export class ReportConcern {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly router = inject(Router);

  readonly categories = CATEGORIES;

  category = signal('welfare');
  summary = signal('');
  details = signal('');
  busy = signal(false);
  reference = signal<string | null>(null);

  submit(): void {
    const summary = this.summary().trim();
    if (!summary) {
      this.toast.error('Please add a short summary of your concern.');
      return;
    }
    this.busy.set(true);
    this.api.post<any>('/backend/safeguarding/cases', {
      category: this.category(),
      summary,
      details: this.details().trim() || null,
    }).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.reference.set(res?.reference ?? null);
        this.toast.success('Thank you. Your concern has been shared with the safeguarding team.');
      },
      error: (e) => {
        this.busy.set(false);
        this.toast.error(e?.error?.error || 'Could not send your report. Please try again.');
      },
    });
  }

  reset(): void {
    this.category.set('welfare');
    this.summary.set('');
    this.details.set('');
    this.reference.set(null);
  }

  done(): void {
    this.router.navigate(['/student/main']);
  }
}
