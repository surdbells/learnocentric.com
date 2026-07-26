import {Component, input} from '@angular/core';
import {RouterLink} from '@angular/router';
import {Icon} from '../icon/icon';

/**
 * Titled card wrapper for the right-hand rail (Summary / Attention / Quick
 * Actions panels on every design screen). Slot content via <ng-content>.
 * Optional header icon and a "View all" link.
 */
@Component({
  selector: 'app-rail-card',
  standalone: true,
  imports: [Icon, RouterLink],
  template: `
    <section class="rc">
      <header class="rc-head">
        <span class="rc-title">
          @if (icon()) { <app-icon [name]="icon()" [size]="17" class="rc-title-icon" /> }
          {{ title() }}
        </span>
        @if (link()) {
          <a class="rc-link" [routerLink]="link()">{{ linkLabel() }} <app-icon name="chevron_right" [size]="15" /></a>
        }
      </header>
      <div class="rc-body"><ng-content /></div>
    </section>
  `,
  styles: [`
    :host { display: block; }
    .rc {
      background: var(--bs-body-bg);
      border: 1px solid var(--bs-border-color);
      border-radius: var(--bs-border-radius-lg);
      box-shadow: var(--shadow-xs);
      padding: 1rem 1.1rem 1.1rem;
    }
    .rc-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .75rem; }
    .rc-title { font-size: .95rem; font-weight: 700; display: inline-flex; align-items: center; gap: .45rem; color: var(--bs-emphasis-color); }
    .rc-title-icon { color: var(--brand-600); }
    .rc-link { font-size: .78rem; font-weight: 600; color: var(--brand-700); text-decoration: none; display: inline-flex; align-items: center; gap: .1rem; white-space: nowrap; }
    .rc-link:hover { text-decoration: underline; }
  `],
})
export class RailCard {
  title = input<string>('');
  icon = input<string>('');
  link = input<string | null>(null);
  linkLabel = input<string>('View all');
}
