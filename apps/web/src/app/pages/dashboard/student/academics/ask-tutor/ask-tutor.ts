import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {Router} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';

/**
 * Learner Ask Tutor, a directory of the learner's subject tutors (with
 * ratings), a Q&A board (ask a question, see answers), and tutor ratings.
 * Direct chat hands off to the existing messaging page. Backed by
 * /ask-tutor/board + /ask-tutor/questions + /ask-tutor/ratings.
 */
@Component({
  selector: 'app-ask-tutor',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, DatePipe],
  templateUrl: './ask-tutor.html',
  styleUrl: './ask-tutor.css',
})
export class AskTutor {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly router = inject(Router);

  loading = signal(true);
  board = signal<any>(null);
  busy = signal(false);

  // ask form
  qSubject = signal<string>('');
  qTutor = signal<string>('');
  qText = signal('');

  readonly tutors = computed<any[]>(() => this.board()?.tutors ?? []);
  readonly myQuestions = computed<any[]>(() => this.board()?.my_questions ?? []);
  readonly answered = computed<any[]>(() => this.board()?.answered ?? []);
  readonly subjects = computed<any[]>(() => this.board()?.subjects ?? []);

  readonly stars = [1, 2, 3, 4, 5];

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/ask-tutor/board').subscribe({
      next: (res) => { this.board.set(res ?? {}); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load Ask Tutor'); },
    });
  }

  askTutor(tutor: any): void {
    this.qTutor.set(String(tutor.id));
    if (tutor.subjects?.length && this.subjects().some((s: any) => s.name === tutor.subjects[0])) {
      const match = this.subjects().find((s: any) => s.name === tutor.subjects[0]);
      if (match) this.qSubject.set(String(match.id));
    }
    document.getElementById('ask-form')?.scrollIntoView({behavior: 'smooth', block: 'center'});
  }

  submitQuestion(): void {
    if (!this.qText().trim()) { this.toast.error('Type your question first'); return; }
    this.busy.set(true);
    this.api.post<any>('/backend/ask-tutor/questions', {
      question: this.qText().trim(),
      subject_id: this.qSubject() || null,
      tutor_id: this.qTutor() || null,
    }).subscribe({
      next: () => {
        this.toast.success('Question sent to your tutor');
        this.qText.set('');
        this.busy.set(false);
        this.load();
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not send'); this.busy.set(false); },
    });
  }

  rate(tutor: any, rating: number): void {
    this.api.post<any>('/backend/ask-tutor/ratings', {tutor_id: tutor.id, rating}).subscribe({
      next: () => { this.toast.success(`You rated ${tutor.name} ${rating}★`); this.load(); },
      error: (e) => this.toast.error(e?.error?.error || 'Could not rate'),
    });
  }

  /** The tutor whose comment box is open (one at a time). */
  commentFor = signal<number | null>(null);
  commentText = signal('');

  openComment(tutor: any): void { this.commentText.set(tutor.my_comment ?? ''); this.commentFor.set(tutor.id); }

  saveComment(tutor: any): void {
    this.busy.set(true);
    this.api.post<any>('/backend/ask-tutor/ratings', {tutor_id: tutor.id, rating: tutor.my_rating, comment: this.commentText().trim()}).subscribe({
      next: () => { this.busy.set(false); this.commentFor.set(null); this.toast.success('Comment saved'); this.load(); },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'Could not save your comment'); },
    });
  }

  message(): void {
    this.router.navigate(['/student/communication/messages']);
  }
}
