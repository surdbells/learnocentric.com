import {Component, input} from '@angular/core';
import {StatCard} from './stat-card';
import {KpiItem} from './ui-types';

/**
 * Responsive row of KPI cards — the metric strip at the top of nearly every
 * design screen. Pass 4–6 items; the grid wraps on narrow viewports.
 * Usage: <app-kpi-strip [items]="kpis" [cols]="6" />
 */
@Component({
  selector: 'app-kpi-strip',
  standalone: true,
  imports: [StatCard],
  template: `
    <div class="kpi-grid" [style.--kpi-cols]="cols()">
      @for (item of items(); track item.label) {
        <app-stat-card [item]="item" />
      }
    </div>
  `,
  styles: [`
    :host { display: block; }
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(var(--kpi-cols, 4), minmax(0, 1fr));
      gap: 1rem;
    }
    @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 768px)  { .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 480px)  { .kpi-grid { grid-template-columns: 1fr; } }
  `],
})
export class KpiStrip {
  items = input<KpiItem[]>([]);
  cols = input<number>(4);
}
