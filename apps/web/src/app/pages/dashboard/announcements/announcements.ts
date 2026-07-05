import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Icon} from '../../../common/icon/icon';

/**
 * School announcements feed. Staff (admins + teachers) can post to an audience;
 * everyone sees the announcements targeted at their role.
 */
@Component({
  selector: 'app-announcements',
  standalone: true,
  imports: [Icon, PageHeader, DatePipe, FormsModule],
  templateUrl: './announcements.html',
  styleUrl: './announcements.css',
})
export class Announcements {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  readonly canPost = signal(['school_admin', 'tutor_admin', 'teacher'].includes(this.auth.getAuthSession()?.user?.role ?? ''));
  readonly audiences = ['all', 'students', 'teachers', 'parents', 'staff'];

  loading = signal(true);
  busy = signal(false);
  items = signal<any[]>([]);
  composing = signal(false);
  form = signal({title: '', body: '', audience: 'all'});

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/messaging/announcements').subscribe({
      next: (res) => { this.items.set(Array.isArray(res) ? res : []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load announcements'); },
    });
  }

  updateForm(patch: Partial<{title: string; body: string; audience: string}>): void {
    this.form.set({...this.form(), ...patch});
  }

  post(): void {
    const f = this.form();
    if (!f.title.trim() || !f.body.trim()) { this.toast.error('A title and message are required'); return; }
    this.busy.set(true);
    this.api.post('/backend/messaging/announcements', f).subscribe({
      next: () => {
        this.toast.success('Announcement posted');
        this.busy.set(false);
        this.composing.set(false);
        this.form.set({title: '', body: '', audience: 'all'});
        this.load();
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not post'); this.busy.set(false); },
    });
  }

  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
