import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

const TYPE_COLOR: Record<string, string> = {praise: 'success', correction: 'warning', reteach: 'info', general: 'secondary'};
const TYPE_ICON: Record<string, string> = {praise: 'celebration', correction: 'edit_note', reteach: 'menu_book', general: 'chat'};

@Component({
  selector: 'app-my-feedback',
  standalone: true,
  imports: [Icon, PageHeader, DatePipe],
  templateUrl: './my-feedback.html',
  styleUrl: './my-feedback.css',
})
export class MyFeedback {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  notes = signal<any[]>([]);
  unread = signal(0);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/assessment/feedback/mine').subscribe({
      next: (res) => { this.notes.set(res?.data ?? []); this.unread.set(res?.meta?.unread ?? 0); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your feedback'); },
    });
  }

  acknowledge(note: any): void {
    this.api.post<any>(`/backend/assessment/feedback/${note.id}/acknowledge`, {}).subscribe({
      next: () => { this.load(); },
      error: () => this.toast.error('Could not update'),
    });
  }

  typeColor(t: string): string { return TYPE_COLOR[t] ?? 'secondary'; }
  typeIcon(t: string): string { return TYPE_ICON[t] ?? 'chat'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
