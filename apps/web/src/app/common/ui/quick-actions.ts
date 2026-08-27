import {Component, input, output} from '@angular/core';
import {NgTemplateOutlet} from '@angular/common';
import {RouterLink} from '@angular/router';
import {Icon} from '../icon/icon';
import {QuickAction, toneVars} from './ui-types';

/**
 * "Quick Actions" panel for the right rail, a stack of icon + label (+ sublabel)
 * rows. Rows with `link` navigate; rows with `key` emit (action). Place inside
 * an <app-rail-card title="Quick Actions">.
 */
@Component({
  selector: 'app-quick-actions',
  standalone: true,
  imports: [Icon, RouterLink, NgTemplateOutlet],
  template: `
    @for (a of actions(); track a.label) {
      @if (a.link) {
        <a class="qa-row" [routerLink]="a.link"
           [style.--ui-tone]="tv(a.tone).color" [style.--ui-tone-rgb]="tv(a.tone).rgb">
          <ng-container *ngTemplateOutlet="row; context: { $implicit: a }" />
        </a>
      } @else {
        <button type="button" class="qa-row" (click)="action.emit(a.key ?? a.label)"
           [style.--ui-tone]="tv(a.tone).color" [style.--ui-tone-rgb]="tv(a.tone).rgb">
          <ng-container *ngTemplateOutlet="row; context: { $implicit: a }" />
        </button>
      }
    }
    <ng-template #row let-a>
      <span class="qa-icon"><app-icon [name]="a.icon" [size]="18" /></span>
      <span class="qa-main">
        <span class="qa-label">{{ a.label }}</span>
        @if (a.sublabel) { <span class="qa-sub">{{ a.sublabel }}</span> }
      </span>
      <app-icon name="chevron_right" [size]="16" class="qa-chev" />
    </ng-template>
  `,
  styles: [`
    :host { display: flex; flex-direction: column; gap: .4rem; }
    .qa-row {
      display: flex; align-items: center; gap: .7rem; width: 100%; text-align: left;
      padding: .6rem .65rem; border: 1px solid var(--bs-border-color);
      border-radius: var(--bs-border-radius); background: var(--bs-body-bg);
      text-decoration: none; color: inherit; cursor: pointer;
      transition: border-color .15s ease, background .15s ease;
    }
    .qa-row:hover { border-color: rgba(var(--ui-tone-rgb), .4); background: rgba(var(--ui-tone-rgb), .05); }
    .qa-icon {
      width: 34px; height: 34px; flex: none; border-radius: 9px;
      display: inline-flex; align-items: center; justify-content: center;
      color: var(--ui-tone); background: rgba(var(--ui-tone-rgb), .12);
    }
    .qa-main { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
    .qa-label { font-size: .86rem; font-weight: 600; color: var(--bs-emphasis-color); }
    .qa-sub { font-size: .76rem; color: var(--bs-secondary-color); }
    .qa-chev { color: var(--bs-secondary-color); flex: none; }
  `],
})
export class QuickActions {
  actions = input<QuickAction[]>([]);
  action = output<string>();
  protected tv = toneVars;
}
