import {Component, inject, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {Router} from '@angular/router';
import {ApiService} from '../../service/api.service';
import {Icon} from '../../icon/icon';

const TYPE_ICON: Record<string, string> = {
  feedback: 'forum', grade: 'grading', portfolio: 'folder_special',
  live: 'video_camera_front', billing: 'credit_card', system: 'notifications',
};

@Component({
  selector: 'app-notification-bell',
  standalone: true,
  imports: [Icon, DatePipe],
  templateUrl: './notification-bell.html',
  styleUrl: './notification-bell.css',
})
export class NotificationBell {
  private readonly api = inject(ApiService);
  readonly router = inject(Router);
  private readonly platformId = inject(PLATFORM_ID);

  notifications = signal<any[]>([]);
  unread = signal(0);

  constructor() {
    if (isPlatformBrowser(this.platformId)) {
      this.load();
    }
  }

  load(): void {
    this.api.get<any>('/backend/notifications', {params: {limit: 8}}).subscribe({
      next: (res) => { this.notifications.set(res?.data ?? []); this.unread.set(res?.meta?.unread ?? 0); },
      error: () => {},
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

  markAll(event: Event): void {
    event.stopPropagation();
    this.api.post('/backend/notifications/read-all', {}).subscribe({next: () => this.load()});
  }

  icon(type: string): string { return TYPE_ICON[type] ?? 'notifications'; }
}
