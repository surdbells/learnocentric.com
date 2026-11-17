import {Component, computed, HostListener, Inject, inject, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {RouterOutlet} from '@angular/router';
import {isPlatformBrowser} from '@angular/common';
import {IMenu, UserPreferenceMenu} from '../../common/service/user-preference-menu';
import {AuthUser} from '../../common/auth/auth.models';
import {ModuleAccessService} from '../../common/auth/module-access.service';
import {BrandThemeService} from '../../common/service/brand-theme.service';
import {Sidenav} from '../../common/layout/sidenav/sidenav';
import {Topbar} from '../../common/layout/topbar/topbar';
import {NavigationEnd, Router, RouterLink} from '@angular/router';
import {roleRoot} from '../../common/auth/portal';
import {Icon} from '../../common/icon/icon';

const COLLAPSE_KEY = 'sidebarCollapsed';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [RouterOutlet, RouterLink, Sidenav, Topbar, Icon],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css',
})
export class Dashboard implements OnInit {

  private readonly moduleAccess = inject(ModuleAccessService);
  private readonly brandTheme = inject(BrandThemeService);
  private readonly router = inject(Router);
  private readonly fullMenu = signal<IMenu[]>([]);

  /** Menu with items the current plan doesn't grant filtered out. */
  readonly menu = computed<IMenu[]>(() => this.awaitingPayment()
    // Before the first payment only Billing is open, so it is the only entry.
    ? [{name: 'Billing', icon: 'credit_card', link: this.billingLink()}]
    : this.filterByModules(this.fullMenu()));

  /** Students get a fully-expanded, non-collapsible menu (all items always visible). */
  readonly alwaysOpenMenu = signal<boolean>(false);

  /** Signed up online and not paid yet: only Billing is open until the first payment is verified. */
  readonly awaitingPayment = computed(() => !!this.moduleAccess.subscription()?.awaiting_payment);
  private readonly url = signal('');
  readonly onBilling = computed(() => this.url().includes('/management/billing'));
  readonly subscriptionOrg = computed(() => this.moduleAccess.subscription()?.organisation ?? 'your organisation');

  /** Tutors, learners and parents whose school's subscription has ended see why, instead of pages that fail. */
  readonly suspended = computed(() => !!this.moduleAccess.subscription()?.suspended);
  readonly suspendedMessage = computed(() => this.moduleAccess.subscription()?.message ?? '');

  /** For the people who pay: a short note when the subscription needs attention, with a link to Billing. */
  readonly billingNotice = computed<{tone: string; text: string} | null>(() => {
    const s = this.moduleAccess.subscription();
    if (!s?.manages_billing) return null;
    const day = (iso: string) => new Date(iso).toLocaleDateString(undefined, {day: 'numeric', month: 'long', year: 'numeric'});
    if (s.status === 'grace') {
      return {tone: 'warning', text: `Payment is due. The subscription was paid to ${day(s.paid_until)}; tutors and learners will be suspended after ${day(s.grace_until)} unless it is renewed.`};
    }
    if (s.status === 'expired' || s.status === 'cancelled') {
      return {tone: 'danger', text: 'The subscription has ended, so tutors and learners cannot use the platform. Renew to restore their access at once; your records are kept.'};
    }
    if (s.renewal_stopped) {
      return {tone: 'info', text: `Renewal is stopped. Access for tutors and learners ends on ${day(s.paid_until)}.`};
    }
    return null;
  });
  readonly billingLink = computed(() => roleRoot(this.role) + '/management/billing');
  private role = '';

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
      this.role = user.role ?? '';
      this.url.set(this.router.url);
      this.router.events.subscribe(e => { if (e instanceof NavigationEnd) this.url.set(e.urlAfterRedirects); });
      this.fullMenu.set(this.userPreferenceMenu[user.role] ?? []);
      this.alwaysOpenMenu.set(user.role === 'student');
      // Re-apply the school's brand colour straight away (no green flash on reload).
      this.brandTheme.restore();
      // Refresh granted modules so the menu reflects the school's current plan.
      this.moduleAccess.refresh();
    }
  }

  ngOnInit(): void {
    if (isPlatformBrowser(this.platformId)) {
      // Students always get the full, expanded sidebar, never the icon rail.
      this.collapsed.set(!this.alwaysOpenMenu() && localStorage.getItem(COLLAPSE_KEY) === '1');
    }
  }

  /** Drop items whose module isn't granted, then drop any group left with no children. */
  private filterByModules(menu: IMenu[]): IMenu[] {
    return menu
      .map(group => {
        const ok = (i: IMenu) => this.moduleAccess.has(i.module) && this.moduleAccess.canView(i.perm);
        if (!group.children?.length) {
          return ok(group) ? group : null;
        }
        if (!this.moduleAccess.canView(group.perm)) return null;
        const children = group.children.filter(ok);
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
