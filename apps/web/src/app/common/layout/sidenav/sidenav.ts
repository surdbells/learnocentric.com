import {Component, inject, input, OnInit, output} from '@angular/core';
import {NgOptimizedImage} from '@angular/common';
import {Router, RouterLink, RouterLinkActive} from '@angular/router';
import {IMenu} from '../../service/user-preference-menu';
import {Preferences} from '../../service/preferences';
import {Icon} from '../../icon/icon';

/**
 * Modern, collapsible sidebar navigation.
 * - Desktop: expands to full width or collapses to an icon rail (state owned by the shell).
 *   In rail mode, hovering a group reveals a flyout submenu (CSS-driven).
 * - Mobile: rendered as an off-canvas drawer by the shell.
 */
@Component({
  selector: 'app-sidenav',
  standalone: true,
  imports: [Icon, RouterLink, RouterLinkActive, NgOptimizedImage],
  templateUrl: './sidenav.html',
  styleUrl: './sidenav.css',
  host: {
    'class': 'app-sidebar',
    '[class.is-rail]': 'collapsed()',
  },
})
export class Sidenav implements OnInit {
  menu = input.required<IMenu[]>();
  collapsed = input<boolean>(false);
  /** When true, every group is expanded and cannot be collapsed (all items always visible). */
  alwaysOpen = input<boolean>(false);
  itemClick = output<void>();

  protected router = inject(Router);
  private prefs = inject(Preferences);

  currentTheme = 'light';
  private readonly openGroups = new Set<string>();

  constructor() {
    this.prefs.theme$.subscribe(t => (this.currentTheme = t));
  }

  ngOnInit(): void {
    // Auto-expand whichever group contains the active route.
    for (const group of this.menu()) {
      if (this.isGroupActive(group)) this.openGroups.add(group.name);
    }
  }

  get dashboardLink(): string {
    return `/${this.router.url.split('/')[1]}/main`;
  }

  isChildActive(link: string): boolean {
    const url = this.router.url;
    const clean = link.replace(/\/$/, '');
    return url === link || url === clean || url.startsWith(clean + '/');
  }

  isGroupActive(group: IMenu): boolean {
    if (group.children?.length) return group.children.some(c => this.isChildActive(c.link));
    return this.isChildActive(group.link);
  }

  isOpen(group: IMenu): boolean {
    return this.alwaysOpen() || this.openGroups.has(group.name);
  }

  toggleGroup(group: IMenu, event?: Event): void {
    event?.preventDefault();
    if (this.alwaysOpen()) return; // groups are pinned open; nothing to toggle
    if (this.openGroups.has(group.name)) this.openGroups.delete(group.name);
    else this.openGroups.add(group.name);
  }

  onItemClick(): void {
    this.itemClick.emit();
  }
}
