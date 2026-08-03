import {Component, computed, input} from '@angular/core';

export interface HeatRow { label: string; values: number[]; }

/**
 * Calendar-style heatmap (rows × columns, e.g. weeks × weekdays). Cell intensity
 * scales green from light to dark against the max value.
 */
@Component({
  selector: 'app-heat-grid',
  standalone: true,
  template: `
    <div class="hg" [style.grid-template-columns]="templateCols()">
      <div class="hg-corner"></div>
      @for (c of cols(); track $index) { <div class="hg-col-h">{{ c }}</div> }
      @for (r of rowsC(); track $index) {
        <div class="hg-row-h">{{ r.label }}</div>
        @for (cell of r.cells; track $index) {
          <div class="hg-cell" [style.background]="cell.bg" [attr.title]="cell.value"></div>
        }
      }
    </div>
    <div class="hg-scale">
      <span>Lower</span>
      @for (s of scale; track $index) { <span class="hg-swatch" [style.background]="s"></span> }
      <span>Higher</span>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .hg { display: grid; gap: 4px; align-items: center; }
    .hg-col-h { font-size: .68rem; color: var(--bs-secondary-color); text-align: center; }
    .hg-row-h { font-size: .68rem; color: var(--bs-secondary-color); white-space: nowrap; padding-right: .4rem; text-align: right; }
    .hg-cell { aspect-ratio: 1 / 1; min-height: 22px; border-radius: 4px; }
    .hg-scale { display: flex; align-items: center; gap: 4px; margin-top: .6rem; font-size: .7rem; color: var(--bs-secondary-color); }
    .hg-swatch { width: 16px; height: 12px; border-radius: 3px; }
  `],
})
export class HeatGrid {
  cols = input<string[]>([]);
  rows = input<HeatRow[]>([]);

  protected readonly scale = [0.12, 0.32, 0.55, 0.78, 1].map(a => `rgba(var(--brand-rgb), ${a})`);

  /** A label column plus one equal column per data column. */
  protected readonly templateCols = computed(() => `minmax(56px, auto) repeat(${Math.max(this.cols().length, 1)}, 1fr)`);

  private readonly max = computed(() => Math.max(...this.rows().flatMap(r => r.values), 1));

  /** Rows enriched with per-cell background intensity. */
  protected readonly rowsC = computed(() => {
    const max = this.max();
    return this.rows().map(r => ({
      label: r.label,
      cells: r.values.map(v => ({
        value: v,
        bg: `rgba(var(--brand-rgb), ${Math.max(v / max, v > 0 ? 0.08 : 0.04).toFixed(2)})`,
      })),
    }));
  });
}
