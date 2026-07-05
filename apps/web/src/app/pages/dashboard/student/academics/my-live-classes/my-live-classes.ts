import {Component, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LiveRoom} from '../../../../../common/live-room/live-room';
import {ApiService} from '../../../../../common/service/api.service';

const STATUS_COLOR: Record<string, string> = {scheduled: 'info', live: 'success', ended: 'secondary', cancelled: 'dark'};

@Component({
  selector: 'app-my-live-classes',
  standalone: true,
  imports: [PageHeader, DatePipe, LiveRoom],
  templateUrl: './my-live-classes.html',
  styleUrl: './my-live-classes.css',
})
export class MyLiveClasses {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  busy = signal<number | null>(null);
  classes = signal<any[]>([]);
  room = signal<{ roomUrl: string; token: string | null; title: string } | null>(null);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/live-classes/upcoming').subscribe({
      next: (res) => { this.classes.set(res?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your classes'); },
    });
  }

  join(lc: any): void {
    this.busy.set(lc.id);
    this.api.post<any>(`/backend/live-classes/${lc.id}/join`, {}).subscribe({
      next: (res) => {
        this.busy.set(null);
        if (res?.room_url) {
          this.room.set({roomUrl: res.room_url, token: res.token ?? null, title: res.title || lc.title});
        } else {
          this.toast.error('This class has no room yet.');
        }
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not join'); this.busy.set(null); },
    });
  }

  leaveRoom(): void {
    this.room.set(null);
    this.load();
  }

  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
