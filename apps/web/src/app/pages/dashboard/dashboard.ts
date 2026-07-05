import {Component, HostListener, Inject, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {RouterOutlet} from '@angular/router';
import {isPlatformBrowser} from '@angular/common';
import {IMenu, UserPreferenceMenu} from '../../common/service/user-preference-menu';
import {AuthUser} from '../../common/auth/auth.models';
import {Sidenav} from '../../common/layout/sidenav/sidenav';
import {Topbar} from '../../common/layout/topbar/topbar';

const COLLAPSE_KEY = 'sidebarCollapsed';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [RouterOutlet, Sidenav, Topbar],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class Dashboard implements OnInit {

  menu: IMenu[] = [];

  /** Desktop rail collapse state (persisted). */
  collapsed = signal<boolean>(false);
  /** Mobile off-canvas drawer state. */
  mobileOpen = signal<boolean>(false);

  constructor(
    @Inject(PLATFORM_ID) private platformId: Object,
    private userPreferenceMenu: UserPreferenceMenu,
  ) {
    if (isPlatformBrowser(platformId)) {
      const user: AuthUser = JSON.parse(localStorage.getItem('auth_user') || '{}');
      this.menu = this.userPreferenceMenu[user.role] ?? [];
    }
  }

  ngOnInit(): void {
    if (isPlatformBrowser(this.platformId)) {
      this.collapsed.set(localStorage.getItem(COLLAPSE_KEY) === '1');
    }
  }

  toggleCollapse(): void {
    const next = !this.collapsed();
    this.collapsed.set(next);
    if (isPlatformBrowser(this.platformId)) {
      localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0');
    }
  }

  openMobile(): void { this.mobileOpen.set(true); }
  closeMobile(): void { this.mobileOpen.set(false); }

  @HostListener('document:keydown.escape')
  onEscape(): void { this.closeMobile(); }
}
