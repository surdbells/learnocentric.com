import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';
import {Icon} from '../../../common/icon/icon';

/**
 * Governed direct messaging. Shows the caller's conversations, a thread with a
 * chosen contact, and a way to start a new conversation with anyone the backend
 * permits (role-pair governed, same institution).
 */
@Component({
  selector: 'app-messages',
  standalone: true,
  imports: [Icon, PageHeader, DatePipe, FormsModule],
  templateUrl: './messages.html',
  styleUrl: './messages.css',
})
export class Messages {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  conversations = signal<any[]>([]);
  contacts = signal<any[]>([]);
  active = signal<any | null>(null);          // {contact, messages}
  draft = signal('');
  busy = signal(false);
  picking = signal(false);                     // showing the contact picker

  constructor() {
    this.loadConversations();
    this.api.get<any>('/backend/messaging/contacts').subscribe({
      next: (res) => this.contacts.set(Array.isArray(res) ? res : []),
      error: () => {},
    });
  }

  loadConversations(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/messaging/conversations').subscribe({
      next: (res) => { this.conversations.set(Array.isArray(res) ? res : []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load messages'); },
    });
  }

  openThread(contactId: number): void {
    this.picking.set(false);
    this.api.get<any>(`/backend/messaging/conversations/${contactId}`).subscribe({
      next: (res) => { this.active.set(res); this.loadConversations(); },
      error: () => this.toast.error('Could not open the conversation'),
    });
  }

  startWith(contact: any): void { this.openThread(contact.id); }

  send(): void {
    const a = this.active();
    const text = this.draft().trim();
    if (!a || !text) return;
    this.busy.set(true);
    this.api.post<any>('/backend/messaging/messages', {recipient_id: a.contact.id, body: text}).subscribe({
      next: () => { this.draft.set(''); this.busy.set(false); this.openThread(a.contact.id); },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not send'); this.busy.set(false); },
    });
  }

  roleLabel(role: string): string {
    return ({school_admin: 'Admin', tutor_admin: 'Admin', teacher: 'Teacher', student: 'Student', parent: 'Parent'} as Record<string, string>)[role] ?? role;
  }
}
