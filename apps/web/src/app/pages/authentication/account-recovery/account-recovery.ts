import {Component, afterNextRender, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ActivatedRoute, RouterLink} from '@angular/router';
import {ApiService} from '../../../common/service/api.service';
import {LearnoButton} from '../../../common/learno-button/learno-button';

type Mode = 'forgot' | 'reset' | 'verify';

/**
 * The three public account-recovery pages, chosen by route data `mode`:
 *  /forgot-password          ask for a reset link
 *  /reset-password?token=    choose a new password
 *  /verify-email?token=      confirm the email address (runs on load)
 */
@Component({
  selector: 'app-account-recovery',
  standalone: true,
  imports: [FormsModule, RouterLink, LearnoButton],
  templateUrl: './account-recovery.html',
  styleUrl: '../sign-in/sign-in.css',
})
export class AccountRecovery {
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);

  readonly mode: Mode = this.route.snapshot.data['mode'] ?? 'forgot';
  private readonly token = this.route.snapshot.queryParamMap.get('token') ?? '';

  busy = signal(false);
  /** Final success message (the form is replaced by it). */
  done = signal<string | null>(null);
  error = signal<string | null>(null);
  /** The reset link itself is bad (expired/used/incomplete), so offer a fresh one. */
  linkProblem = signal(false);
  /** False when the platform can't send email, so people know to ask their school instead. */
  emailAvailable = signal(true);

  email = signal('');
  password = signal('');
  confirm = signal('');

  constructor() {
    if (this.mode === 'verify') {
      // Browser only: confirm as soon as the page opens.
      afterNextRender(() => this.verify());
    } else if (this.mode === 'reset' && !this.token) {
      this.linkProblem.set(true);
      this.error.set('This reset link is incomplete.');
    }
  }

  requestReset(): void {
    this.error.set(null);
    if (!this.email().trim()) { this.error.set('Enter your email address.'); return; }
    this.busy.set(true);
    this.api.post<any>('/backend/auth/password/forgot', {email: this.email().trim()}).subscribe({
      next: (r) => { this.busy.set(false); this.emailAvailable.set(r?.email_available !== false); this.done.set(r?.message ?? 'Check your email for the reset link.'); },
      error: (e) => { this.busy.set(false); this.error.set(e?.error?.error || 'Something went wrong. Please try again.'); },
    });
  }

  resetPassword(): void {
    this.error.set(null);
    this.linkProblem.set(!this.token);
    if (this.password() !== this.confirm()) { this.error.set('The two passwords don\'t match.'); return; }
    this.busy.set(true);
    this.api.post<any>('/backend/auth/password/reset', {token: this.token, password: this.password()}).subscribe({
      next: (r) => { this.busy.set(false); this.done.set(r?.message ?? 'Your password has been changed.'); },
      error: (e) => { this.busy.set(false); this.linkProblem.set(/link/i.test(e?.error?.error ?? '')); this.error.set(e?.error?.error || 'Could not change your password.'); },
    });
  }

  private verify(): void {
    if (!this.token) { this.error.set('This confirmation link is incomplete.'); return; }
    this.busy.set(true);
    this.api.post<any>('/backend/auth/email/verify', {token: this.token}).subscribe({
      next: (r) => { this.busy.set(false); this.done.set(r?.message ?? 'Your email is confirmed.'); },
      error: (e) => { this.busy.set(false); this.error.set(e?.error?.error || 'This confirmation link is invalid or has expired.'); },
    });
  }
}
