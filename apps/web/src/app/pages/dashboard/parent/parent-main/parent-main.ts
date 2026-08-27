import {Component, inject, PLATFORM_ID, signal} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../common/auth/auth.service';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';

@Component({
  selector: 'app-parent-main',
  standalone: true,
  imports: [Icon, RouterLink],
  templateUrl: './parent-main.html',
  styleUrl: './parent-main.css',
})
export class ParentMain {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  children = signal<any[]>([]);
  firstName = signal('');

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'there');
    if (isPlatformBrowser(this.platformId)) {
      this.api.get<any>('/backend/dashboard/parent').subscribe({
        next: (res) => { this.children.set(res?.children ?? []); this.loading.set(false); },
        error: () => this.loading.set(false),
      });
    }
  }

  scoreColor(p: number | null): string {
    if (p === null || p === undefined) return 'secondary';
    if (p >= 70) return 'success';
    if (p >= 50) return 'warning';
    return 'danger';
  }
  pct(v: number | null): string { return v === null || v === undefined ? '-' : v + '%'; }
}
