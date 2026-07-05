import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Icon} from '../../../common/icon/icon';

/**
 * Support centre — list tickets, open a new one, and work a thread. Requesters
 * (any role) see and follow their own; the platform super admin can assign,
 * reply as staff, escalate and resolve. SLA state is shown per ticket.
 */
@Component({
  selector: 'app-support',
  standalone: true,
  imports: [Icon, PageHeader, DatePipe, FormsModule],
  templateUrl: './support.html',
  styleUrl: './support.css',
})
export class Support {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  readonly isStaff = signal(this.auth.getAuthSession()?.user?.role === 'super_admin');

  loading = signal(true);
  busy = signal(false);
  tickets = signal<any[]>([]);
  mode = signal<'list' | 'new' | 'view'>('list');
  current = signal<any | null>(null);

  // New-ticket form
  form = signal({subject: '', message: '', category: 'technical', priority: 'normal'});
  replyText = signal('');

  readonly categories = ['technical', 'billing', 'content', 'account', 'other'];
  readonly priorities = ['low', 'normal', 'high', 'urgent'];
  readonly openCount = computed(() => this.tickets().filter(t => t.status !== 'resolved' && t.status !== 'closed').length);

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/support/tickets').subscribe({
      next: (res) => { this.tickets.set(Array.isArray(res) ? res : (res?.data ?? [])); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load tickets'); },
    });
  }

  startNew(): void {
    this.form.set({subject: '', message: '', category: 'technical', priority: 'normal'});
    this.mode.set('new');
  }

  submitNew(): void {
    const f = this.form();
    if (!f.subject.trim() || !f.message.trim()) { this.toast.error('A subject and a message are required'); return; }
    this.busy.set(true);
    this.api.post<any>('/backend/support/tickets', f).subscribe({
      next: (t) => { this.toast.success('Ticket opened'); this.busy.set(false); this.open(t); this.load(); },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not open the ticket'); this.busy.set(false); },
    });
  }

  open(ticket: any): void {
    this.mode.set('view');
    this.replyText.set('');
    this.api.get<any>(`/backend/support/tickets/${ticket.id}`).subscribe({
      next: (t) => this.current.set(t),
      error: () => { this.toast.error('Could not load the ticket'); this.mode.set('list'); },
    });
  }

  sendReply(): void {
    const t = this.current();
    if (!t || !this.replyText().trim()) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/support/tickets/${t.id}/reply`, {body: this.replyText()}).subscribe({
      next: (res) => { this.current.set(res); this.replyText.set(''); this.busy.set(false); },
      error: (e) => { this.toast.error(e?.error?.error || 'Reply failed'); this.busy.set(false); },
    });
  }

  transition(action: string, status?: string): void {
    const t = this.current();
    if (!t) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/support/tickets/${t.id}/transition`, {action, status}).subscribe({
      next: (res) => { this.current.set(res); this.busy.set(false); this.load(); },
      error: (e) => { this.toast.error(e?.error?.error || 'Update failed'); this.busy.set(false); },
    });
  }

  backToList(): void { this.current.set(null); this.mode.set('list'); this.load(); }

  updateForm(patch: Partial<{subject: string; message: string; category: string; priority: string}>): void {
    this.form.set({...this.form(), ...patch});
  }

  statusColor(s: string): string {
    return {open: 'primary', in_progress: 'info', waiting: 'warning', resolved: 'success', closed: 'secondary'}[s] ?? 'secondary';
  }
  priorityColor(p: string): string {
    return {low: 'secondary', normal: 'info', high: 'warning', urgent: 'danger'}[p] ?? 'secondary';
  }
  titleCase(s: string): string { return s ? s.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase()) : ''; }
}
