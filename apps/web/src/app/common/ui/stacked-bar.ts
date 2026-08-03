import {Component, computed, input} from '@angular/core';
import {Tone, toneVars} from './ui-types';

export interface BarSeries { label: string; tone: Tone; values: number[]; }

/**
 * SVG stacked-bar chart over a set of categories, with a legend. Used for
 * completion-trend style widgets (completed / in-progress / not-started).
 */
@Component({
  selector: 'app-stacked-bar',
  standalone: true,
  template: `
    <svg [attr.viewBox]="'0 0 ' + W + ' ' + H" class="sbc" preserveAspectRatio="none" role="img">
      @for (col of columns(); track $index) {
        @for (seg of col.segs; track $index) {
          <rect [attr.x]="col.x" [attr.y]="seg.y" [attr.width]="bw()" [attr.height]="seg.h"
                [attr.fill]="seg.color" rx="1.5" />
        }
      }
    </svg>
    <div class="sbc-x">
      @for (l of labels(); track $index) { <span>{{ l }}</span> }
    </div>
    <div class="sbc-legend">
      @for (s of series(); track s.label) {
        <span class="sbc-key"><span class="sbc-dot" [style.background]="color(s.tone)"></span>{{ s.label }}</span>
      }
    </div>
  `,
  styles: [`
    :host { display: block; }
    .sbc { width: 100%; height: var(--sbc-h, 180px); }
    .sbc-x { display: flex; justify-content: space-between; margin-top: .35rem; }
    .sbc-x span { font-size: .7rem; color: var(--bs-secondary-color); }
    .sbc-legend { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: .5rem; }
    .sbc-key { display: inline-flex; align-items: center; gap: .35rem; font-size: .78rem; color: var(--bs-body-color); }
    .sbc-dot { width: 9px; height: 9px; border-radius: 3px; }
  `],
})
export class StackedBar {
  labels = input<string[]>([]);
  series = input<BarSeries[]>([]);

  protected readonly W = 640;
  protected readonly H = 200;
  protected readonly gap = 6;

  color(t: Tone): string { return toneVars(t).color; }

  protected readonly n = computed(() => Math.max(this.labels().length, 1));
  protected readonly bw = computed(() => Math.max((this.W - this.gap * (this.n() - 1)) / this.n(), 1));

  /** Per-category stacked segments, bottom-up. */
  protected readonly columns = computed(() => {
    const s = this.series();
    const n = this.n();
    const bw = this.bw();
    // Column totals to find the max stack height.
    const totals: number[] = [];
    for (let i = 0; i < n; i++) {
      totals[i] = s.reduce((sum, ser) => sum + (ser.values[i] ?? 0), 0);
    }
    const max = Math.max(...totals, 1);
    return Array.from({length: n}, (_, i) => {
      let yBottom = this.H;
      const segs = s.map(ser => {
        const val = ser.values[i] ?? 0;
        const h = (val / max) * this.H;
        yBottom -= h;
        return {y: yBottom, h: Math.max(h, val > 0 ? 1 : 0), color: toneVars(ser.tone).color};
      });
      return {x: i * (bw + this.gap), segs};
    });
  });
}
