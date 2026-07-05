import {Component, inject, PLATFORM_ID, signal} from '@angular/core';
import {DecimalPipe, isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

@Component({
  selector: 'app-super-admin-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, DecimalPipe],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class SuperAdminDashboard {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  data = signal<any | null>(null);
  firstName = signal('');

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'Admin');
    if (isPlatformBrowser(this.platformId)) {
      this.api.get<any>('/backend/dashboard/super-admin').subscribe({
        next: (res) => { this.data.set(res); this.loading.set(false); },
        error: () => this.loading.set(false),
      });
    }
  }
}
