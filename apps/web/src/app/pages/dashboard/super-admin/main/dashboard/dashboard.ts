import {Component, computed, inject, PLATFORM_ID, signal} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, QuickAction, QuickActions, RailCard} from '../../../../../common/ui';

const M = '/super-admin/management';

@Component({
  selector: 'app-super-admin-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, KpiStrip, RailCard, QuickActions],
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

  readonly kpis = computed<KpiItem[]>(() => {
    const d = this.data();
    if (!d) return [];
    const s = d.stats;
    return [
      {label: 'Institutions', value: s.institutions, icon: 'apartment', tone: 'primary', link: `${M}/institutions`},
      {label: 'Active subscriptions', value: s.active_subscriptions, icon: 'credit_card', tone: 'success', link: `${M}/plans`},
      {label: 'Plans', value: s.plans, icon: 'workspace_premium', tone: 'info', link: `${M}/plans`},
      {label: 'Students', value: s.students, icon: 'group', tone: 'primary'},
      {label: 'Teachers', value: s.teachers, icon: 'supervisor_account', tone: 'warning'},
      {label: 'Billed', value: '₦' + Number(s.billed_naira ?? 0).toLocaleString(), icon: 'wallet', tone: 'success'},
    ];
  });

  readonly quickActions: QuickAction[] = [
    {label: 'Onboard School', sublabel: 'Add a new institution', icon: 'apartment', link: `${M}/institutions`},
    {label: 'Subscription Plans', sublabel: 'Manage plans', icon: 'credit_card', link: `${M}/plans`},
    {label: 'Content Library', sublabel: 'Platform resources', icon: 'library_books', link: `${M}/content-library`},
    {label: 'Support Centre', sublabel: 'Tickets & escalations', icon: 'headset', link: `${M}/support`},
  ];

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'Admin');
    if (isPlatformBrowser(this.platformId)) {
      this.api.get<any>('/backend/dashboard/super-admin').subscribe({
        next: (res) => { this.data.set(res); this.loading.set(false); },
        error: () => this.loading.set(false),
      });
    }
  }

  readonly mgmt = M;
}
