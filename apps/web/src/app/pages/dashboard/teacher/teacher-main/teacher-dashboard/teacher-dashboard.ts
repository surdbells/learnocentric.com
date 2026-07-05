import {Component, inject, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';

@Component({
  selector: 'app-teacher-dashboard',
  standalone: true,
  imports: [RouterLink, DatePipe],
  templateUrl: './teacher-dashboard.html',
  styleUrl: './teacher-dashboard.css',
})
export class TeacherDashboard {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  data = signal<any | null>(null);
  firstName = signal('');

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'there');
    if (isPlatformBrowser(this.platformId)) {
      this.api.get<any>('/backend/dashboard/teacher').subscribe({
        next: (res) => { this.data.set(res); this.loading.set(false); },
        error: () => this.loading.set(false),
      });
    }
  }
}
