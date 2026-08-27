import {Component, signal} from '@angular/core';
import {RouterLink, RouterLinkActive, RouterOutlet} from '@angular/router';

/** Public marketing shell, shared header, closing CTA band and footer around the routed page. */
@Component({
  selector: 'app-public-layout',
  standalone: true,
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './public-layout.html',
})
export class PublicLayout {
  readonly year = 2026;

  /** Mobile nav drawer state (the desktop nav is hidden under 860px). */
  readonly menuOpen = signal(false);
  toggleMenu(): void { this.menuOpen.update((v) => !v); }
  closeMenu(): void { this.menuOpen.set(false); }
}
