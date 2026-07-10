import {Component, computed, HostListener, Inject, inject, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {RouterOutlet} from '@angular/router';
import {isPlatformBrowser} from '@angular/common';
import {IMenu, UserPreferenceMenu} from '../../common/service/user-preference-menu';
import {AuthUser} from '../../common/auth/auth.models';
import {ModuleAccessService} from '../../common/auth/module-access.service';
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

  private readonly moduleAccess = inject(ModuleAccessService);
  private readonly fullMenu = signal<IMenu[]>([]);

  /** Menu with items the current plan doesn't grant filtered out. */
  readonly menu = computed<IMenu[]>(() => this.filterByModules(this.fullMenu()));

  /** Students get a fully-expanded, non-collapsible menu (all items always visible). */
  readonly alwaysOpenMenu = signal<boolean>(false);

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
      this.fullMenu.set(this.userPreferenceMenu[user.role] ?? []);
      this.alwaysOpenMenu.set(user.role === 'student');
      // Refresh granted modules so the menu reflects the school's current plan.
      this.moduleAccess.refresh();
    }
  }

  ngOnInit(): void {
    if (isPlatformBrowser(this.platformId)) {
      // Students always get the full, expanded sidebar — never the icon rail.
      this.collapsed.set(!this.alwaysOpenMenu() && localStorage.getItem(COLLAPSE_KEY) === '1');
    }
  }

  /** Drop items whose module isn't granted, then drop any group left with no children. */
  private filterByModules(menu: IMenu[]): IMenu[] {
    return menu
      .map(group => {
        if (!group.children?.length) {
          return this.moduleAccess.has(group.module) ? group : null;
        }
        const children = group.children.filter(c => this.moduleAccess.has(c.module));
        return children.length ? {...group, children} : null;
      })
      .filter((g): g is IMenu => g !== null);
  }

  toggleCollapse(): void {
    if (this.alwaysOpenMenu()) return; // students stay on the full expanded sidebar
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
