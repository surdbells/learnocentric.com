import {Component, computed, inject, input, OnInit, output, PLATFORM_ID, signal} from '@angular/core';
import {isPlatformBrowser, Location} from '@angular/common';
import {Router} from '@angular/router';
import {AuthService} from '../../auth/auth.service';
import {AuthUser} from '../../auth/auth.models';
import {ApiService} from '../../service/api.service';
import {PreferenceSetting} from '../preference-setting/preference-setting';
import {NotificationBell} from '../notification-bell/notification-bell';
import {Preferences} from '../../service/preferences';

@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [PreferenceSetting, NotificationBell],
  templateUrl: './topbar.html',
  styleUrl: './topbar.css',
  host: { 'class': 'app-topbar' },
})
export class Topbar implements OnInit {
  collapsed = input<boolean>(false);
  toggleSidebar = output<void>();
  openMobile = output<void>();

  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);
  private readonly location = inject(Location);
  private readonly apiSrv = inject(ApiService);
  private readonly prefs = inject(Preferences);
  private readonly platformId = inject(PLATFORM_ID);

  user = signal<AuthUser | null>(null);
  institution = signal<any | null>(null);
  /** Whether the currently-applied theme is dark (resolves 'system' against the OS). */
  isDark = signal(false);

  /** Human-friendly title derived from the current route's last segment. */
  pageTitle = computed(() => {
    const segments = this.router.url.split('?')[0].split('/').filter(Boolean);
    const last = segments[segments.length - 1] || 'dashboard';
    if (last === 'main') return 'Dashboard';
    return last
      .replace(/-/g, ' ')
      .replace(/\b\w/g, c => c.toUpperCase());
  });

  avatar = computed(() => {
    const u = this.user();
    const src = u?.role?.includes('admin') ? this.institution()?.logo_url : u?.profileImageUrl;
    return src || 'images/avatar-placeholder.svg';
  });

  constructor() {
    this.user.set(this.authService.getAuthSession().user);
    this.prefs.theme$.subscribe(pref => this.isDark.set(this.prefs.resolveTheme(pref) === 'dark'));
  }

  /** One-click toggle between explicit light and dark. */
  toggleTheme(): void {
    this.prefs.setTheme(this.isDark() ? 'light' : 'dark');
  }

  ngOnInit(): void {
    if (isPlatformBrowser(this.platformId) && this.user()?.institutionId) {
      this.apiSrv.get('/backend/admin/institutions/' + this.user()?.institutionId)
        .subscribe(res => this.institution.set(res));
    }
  }

  signOut(): void {
    this.authService.logoutLocal();
    this.router.navigate(['/']);
  }

  goBack(): void {
    this.location.back();
  }
}
