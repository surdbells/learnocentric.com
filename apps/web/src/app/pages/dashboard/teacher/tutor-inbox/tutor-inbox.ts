import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../common/icon/icon';
import {ApiService} from '../../../../common/service/api.service';

/**
 * Tutor inbox, the teacher side of Ask Tutor. Questions directed to the tutor
 * or asked within their subjects; the tutor types an answer, which notifies the
 * learner and closes the question. Backed by /ask-tutor/inbox + answer.
 */
@Component({
  selector: 'app-tutor-inbox',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, DatePipe],
  templateUrl: './tutor-inbox.html',
  styleUrl: './tutor-inbox.css',
})
export class TutorInbox {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  questions = signal<any[]>([]);
  openCount = signal(0);
  drafts = signal<Record<number, string>>({});
  busy = signal<number | null>(null);
  filter = signal<'all' | 'open' | 'answered'>('open');

  readonly filtered = computed<any[]>(() => {
    const f = this.filter();
    return f === 'all' ? this.questions() : this.questions().filter(q => q.status === f);
  });

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/ask-tutor/inbox').subscribe({
      next: (res) => { this.questions.set(res?.data ?? []); this.openCount.set(res?.meta?.open ?? 0); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load questions'); },
    });
  }

  setDraft(id: number, text: string): void { this.drafts.set({...this.drafts(), [id]: text}); }

  answer(q: any): void {
    const text = (this.drafts()[q.id] ?? '').trim();
    if (!text) { this.toast.error('Type your answer first'); return; }
    this.busy.set(q.id);
    this.api.post<any>(`/backend/ask-tutor/questions/${q.id}/answer`, {answer: text}).subscribe({
      next: () => { this.toast.success('Answer sent to the learner'); this.busy.set(null); this.load(); },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not send'); this.busy.set(null); },
    });
  }
}
