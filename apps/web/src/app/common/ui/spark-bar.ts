import {Component, computed, input} from '@angular/core';
import {Tone, toneVars} from './ui-types';

/** Tiny inline-SVG bar sparkline for KPI trends. Self-contained, no chart lib. */
@Component({
  selector: 'app-spark-bar',
  standalone: true,
  template: `
    <svg [attr.width]="width()" [attr.height]="height()" [attr.viewBox]="viewBox()"
         [style.--ui-tone]="tv().color" preserveAspectRatio="none" aria-hidden="true">
      @for (b of bars(); track $index) {
        <rect [attr.x]="b.x" [attr.y]="b.y" [attr.width]="bw()" [attr.height]="b.h"
              rx="1.2" fill="var(--ui-tone)" [attr.opacity]="b.last ? 1 : 0.35" />
      }
    </svg>
  `,
  styles: [`:host{display:inline-flex;line-height:0}`],
})
export class SparkBar {
  values = input<number[]>([]);
  tone = input<Tone>('primary');
  width = input<number>(72);
  height = input<number>(28);

  protected readonly tv = computed(() => toneVars(this.tone()));
  protected readonly gap = 2;
  protected readonly bw = computed(() => {
    const n = Math.max(this.values().length, 1);
    return Math.max((this.width() - this.gap * (n - 1)) / n, 1);
  });
  protected readonly viewBox = computed(() => `0 0 ${this.width()} ${this.height()}`);
  protected readonly bars = computed(() => {
    const v = this.values();
    const max = Math.max(...v, 1);
    const bw = this.bw();
    return v.map((val, i) => {
      const h = Math.max((val / max) * this.height(), 2);
      return { x: i * (bw + this.gap), y: this.height() - h, h, last: i === v.length - 1 };
    });
  });
}
