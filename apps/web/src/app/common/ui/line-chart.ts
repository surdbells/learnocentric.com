import {Component, computed, input} from '@angular/core';
import {Tone, toneVars} from './ui-types';

/**
 * Self-contained SVG line/area chart for trend widgets (DAU, usage, revenue).
 * Green by default; no chart library. Scales to its container width.
 */
@Component({
  selector: 'app-line-chart',
  standalone: true,
  template: `
    <svg [attr.viewBox]="'0 0 ' + W + ' ' + H" class="lc" [style.--ui-tone]="tv().color" [style.--ui-tone-rgb]="tv().rgb"
         preserveAspectRatio="none" role="img">
      <defs>
        <linearGradient [attr.id]="gid()" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="var(--ui-tone)" stop-opacity="0.22" />
          <stop offset="100%" stop-color="var(--ui-tone)" stop-opacity="0" />
        </linearGradient>
      </defs>
      <!-- horizontal gridlines -->
      @for (g of gridLines(); track $index) {
        <line [attr.x1]="padX" [attr.x2]="W - padX" [attr.y1]="g" [attr.y2]="g" class="lc-grid" />
      }
      @if (area()) { <path [attr.d]="areaPath()" [attr.fill]="'url(#' + gid() + ')'" /> }
      <path [attr.d]="linePath()" class="lc-line" />
      @for (p of pts(); track $index) {
        @if (p.last) { <circle [attr.cx]="p.x" [attr.cy]="p.y" r="4" class="lc-dot" /> }
      }
    </svg>
    @if (labels().length) {
      <div class="lc-x">
        @for (l of labels(); track $index) { <span>{{ l }}</span> }
      </div>
    }
  `,
  styles: [`
    :host { display: block; }
    .lc { width: 100%; height: var(--lc-h, 180px); overflow: visible; }
    .lc-line { fill: none; stroke: var(--ui-tone); stroke-width: 2.25; stroke-linejoin: round; stroke-linecap: round;
               vector-effect: non-scaling-stroke; }
    .lc-grid { stroke: var(--bs-border-color); stroke-width: 1; vector-effect: non-scaling-stroke; opacity: .5; }
    .lc-dot { fill: var(--ui-tone); stroke: var(--bs-body-bg); stroke-width: 2; }
    .lc-x { display: flex; justify-content: space-between; margin-top: .4rem; }
    .lc-x span { font-size: .7rem; color: var(--bs-secondary-color); }
  `],
})
export class LineChart {
  points = input<number[]>([]);
  labels = input<string[]>([]);
  tone = input<Tone>('primary');
  area = input<boolean>(true);
  height = input<number>(180);

  protected readonly W = 640;
  protected readonly H = 220;
  protected readonly padX = 6;
  protected readonly padY = 12;

  protected readonly tv = computed(() => toneVars(this.tone()));
  /** Stable-ish gradient id derived from the tone (no Math.random for SSR safety). */
  protected readonly gid = computed(() => 'lcg-' + this.tone());

  protected readonly pts = computed(() => {
    const v = this.points();
    if (!v.length) return [] as {x: number; y: number; last: boolean}[];
    const max = Math.max(...v, 1), min = Math.min(...v, 0);
    const span = max - min || 1;
    const innerW = this.W - this.padX * 2, innerH = this.H - this.padY * 2;
    const step = v.length > 1 ? innerW / (v.length - 1) : 0;
    return v.map((val, i) => ({
      x: this.padX + i * step,
      y: this.padY + innerH - ((val - min) / span) * innerH,
      last: i === v.length - 1,
    }));
  });

  protected readonly linePath = computed(() => this.pts().map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '));
  protected readonly areaPath = computed(() => {
    const p = this.pts();
    if (!p.length) return '';
    const base = this.H - this.padY;
    return `M${p[0].x.toFixed(1)},${base} ` + p.map(q => `L${q.x.toFixed(1)},${q.y.toFixed(1)}`).join(' ') + ` L${p[p.length - 1].x.toFixed(1)},${base} Z`;
  });

  protected readonly gridLines = computed(() => {
    const innerH = this.H - this.padY * 2;
    return [0, 0.25, 0.5, 0.75, 1].map(f => this.padY + innerH * f);
  });
}
