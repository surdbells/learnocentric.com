import {Component, input} from '@angular/core';
import {RouterLink} from '@angular/router';
import {Icon} from '../icon/icon';
import {AttentionItem, toneVars} from './ui-types';

/**
 * "Attention Needed" / alerts / recent-activity list for the right rail.
 * Each row: tinted icon, label + sublabel, optional count badge or time, and
 * an optional deep-link. Place inside an <app-rail-card>.
 */
@Component({
  selector: 'app-attention-list',
  standalone: true,
  imports: [Icon, RouterLink],
  template: `
    @for (item of items(); track $index) {
      <a class="al-row" [class.al-link]="!!item.link" [routerLink]="item.link || null"
         [style.--ui-tone]="tv(item.tone).color" [style.--ui-tone-rgb]="tv(item.tone).rgb">
        @if (item.icon) { <span class="al-icon"><app-icon [name]="item.icon" [size]="16" /></span> }
        <span class="al-main">
          <span class="al-label">{{ item.label }}</span>
          @if (item.sublabel) { <span class="al-sub">{{ item.sublabel }}</span> }
        </span>
        @if (item.count !== undefined && item.count !== null) {
          <span class="al-count">{{ item.count }}</span>
        } @else if (item.time) {
          <span class="al-time">{{ item.time }}</span>
        }
      </a>
    }
    @if (!items().length) { <p class="al-empty">Nothing needs attention.</p> }
  `,
  styles: [`
    :host { display: block; }
    .al-row {
      display: flex; align-items: center; gap: .65rem;
      padding: .55rem .35rem; border-radius: var(--bs-border-radius);
      text-decoration: none; color: inherit;
      border-bottom: 1px solid var(--bs-border-color);
    }
    .al-row:last-child { border-bottom: 0; }
    .al-link { cursor: pointer; }
    .al-link:hover { background: rgba(var(--ui-tone-rgb), .06); }
    .al-icon {
      width: 30px; height: 30px; flex: none; border-radius: 9px;
      display: inline-flex; align-items: center; justify-content: center;
      color: var(--ui-tone); background: rgba(var(--ui-tone-rgb), .12);
    }
    .al-main { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
    .al-label { font-size: .85rem; font-weight: 600; color: var(--bs-emphasis-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .al-sub { font-size: .76rem; color: var(--bs-secondary-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .al-count {
      flex: none; font-size: .75rem; font-weight: 700; color: var(--ui-tone);
      background: rgba(var(--ui-tone-rgb), .14); border-radius: 999px; padding: .1rem .5rem;
    }
    .al-time { flex: none; font-size: .73rem; color: var(--bs-secondary-color); }
    .al-empty { font-size: .82rem; color: var(--bs-secondary-color); margin: .25rem 0 0; }
  `],
})
export class AttentionList {
  items = input<AttentionItem[]>([]);
  protected tv = toneVars;
}
