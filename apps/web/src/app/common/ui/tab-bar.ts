import {Component, input, model} from '@angular/core';
import {TabItem} from './ui-types';

/**
 * Segmented tab bar with optional per-tab counts — the sub-view switcher the
 * designs use on most screens. Two-way bound active key.
 * Usage: <app-tab-bar [tabs]="tabs" [(active)]="tab" />
 */
@Component({
  selector: 'app-tab-bar',
  standalone: true,
  template: `
    <div class="tabs" role="tablist">
      @for (t of tabs(); track t.key) {
        <button type="button" class="tab" role="tab"
                [class.active]="active() === t.key"
                [attr.aria-selected]="active() === t.key"
                (click)="active.set(t.key)">
          {{ t.label }}
          @if (t.count !== undefined && t.count !== null) {
            <span class="tab-count">{{ t.count }}</span>
          }
        </button>
      }
    </div>
  `,
  styles: [`
    :host { display: block; }
    .tabs {
      display: flex; gap: .25rem; overflow-x: auto;
      border-bottom: 1px solid var(--bs-border-color);
      scrollbar-width: none;
    }
    .tabs::-webkit-scrollbar { display: none; }
    .tab {
      position: relative; border: 0; background: transparent; cursor: pointer;
      padding: .6rem .9rem; font-size: .86rem; font-weight: 600; white-space: nowrap;
      color: var(--bs-secondary-color);
      display: inline-flex; align-items: center; gap: .4rem;
      transition: color .15s ease;
    }
    .tab:hover { color: var(--bs-body-color); }
    .tab.active { color: var(--brand-700); }
    .tab.active::after {
      content: ""; position: absolute; left: .6rem; right: .6rem; bottom: -1px; height: 2px;
      background: var(--brand-500); border-radius: 2px 2px 0 0;
    }
    .tab-count {
      font-size: .72rem; font-weight: 700; line-height: 1;
      padding: .12rem .4rem; border-radius: 999px;
      background: rgba(var(--brand-rgb), .12); color: var(--brand-700);
    }
    .tab:not(.active) .tab-count { background: var(--bs-secondary-bg); color: var(--bs-secondary-color); }
  `],
})
export class TabBar {
  tabs = input<TabItem[]>([]);
  active = model<string>('');
}
