import {Component, inject, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {Router} from '@angular/router';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';

const TYPE_ICON: Record<string, string> = {
  feedback: 'forum', grade: 'grading', portfolio: 'folder_special',
  live: 'video_camera_front', billing: 'credit_card', system: 'notifications',
};
const TYPE_COLOR: Record<string, string> = {
  feedback: 'info', grade: 'success', portfolio: 'primary', live: 'success', billing: 'warning', system: 'secondary',
};

@Component({
  selector: 'app-notifications',
  standalone: true,
  imports: [PageHeader, DatePipe],
  templateUrl: './notifications.html',
  styleUrl: './notifications.css',
})
export class Notifications {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  items = signal<any[]>([]);
  unread = signal(0);

  constructor() {
    if (isPlatformBrowser(this.platformId)) {
      this.load();
    }
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/notifications', {params: {limit: 100}}).subscribe({
      next: (res) => { this.items.set(res?.data ?? []); this.unread.set(res?.meta?.unread ?? 0); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  open(n: any): void {
    if (!n.read) {
      this.api.post(`/backend/notifications/${n.id}/read`, {}).subscribe({next: () => this.load()});
    }
    if (n.link) {
      this.router.navigateByUrl(n.link);
    }
  }

  markAll(): void {
    this.api.post('/backend/notifications/read-all', {}).subscribe({next: () => this.load()});
  }

  icon(type: string): string { return TYPE_ICON[type] ?? 'notifications'; }
  color(type: string): string { return TYPE_COLOR[type] ?? 'secondary'; }
}
