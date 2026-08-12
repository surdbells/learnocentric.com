import {Component, computed, input} from '@angular/core';
import {Tone, toneVars} from './ui-types';

export interface DonutSegment { label: string; value: number; tone?: Tone; }

/**
 * SVG donut with a centre total and a legend. Used for role/status splits.
 * Segment colours cycle through the tone palette (green-led).
 */
@Component({
  selector: 'app-donut-chart',
  standalone: true,
  template: `
    <div class="dc-wrap">
      <svg viewBox="0 0 120 120" class="dc" role="img">
        <circle cx="60" cy="60" [attr.r]="r" fill="none"
                stroke="var(--bs-secondary-bg)" [attr.stroke-width]="stroke" />
        @for (a of arcs(); track $index) {
          <circle cx="60" cy="60" [attr.r]="r"
                  [attr.stroke]="a.color" [attr.stroke-dasharray]="a.dash" [attr.stroke-dashoffset]="a.offset"
                  fill="none" [attr.stroke-width]="stroke" transform="rotate(-90 60 60)" />
        }
        <text x="60" y="55" class="dc-total" text-anchor="middle">{{ total() }}</text>
        <text x="60" y="72" class="dc-cap" text-anchor="middle">{{ caption() }}</text>
      </svg>
      <ul class="dc-legend">
        @for (s of computed(); track s.label) {
          <li>
            <span class="dc-dot" [style.background]="s.color"></span>
            <span class="dc-lbl">{{ s.label }}</span>
            <span class="dc-val">{{ s.value }} <em>({{ s.pct }}%)</em></span>
          </li>
        }
      </ul>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .dc-wrap { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
    .dc { width: 128px; height: 128px; flex: 0 0 auto; }
    .dc-total { font-size: 20px; font-weight: 700; fill: var(--bs-body-color); }
    .dc-cap { font-size: 8px; fill: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: .04em; }
    .dc-legend { list-style: none; margin: 0; padding: 0; flex: 1 1 auto; min-width: 140px; }
    .dc-legend li { display: flex; align-items: center; gap: .5rem; padding: .18rem 0; font-size: .82rem; }
    .dc-dot { width: 9px; height: 9px; border-radius: 3px; flex: 0 0 auto; }
    .dc-lbl { flex: 1 1 auto; color: var(--bs-body-color); }
    .dc-val { color: var(--bs-body-color); font-weight: 600; }
    .dc-val em { color: var(--bs-secondary-color); font-weight: 400; font-style: normal; }
  `],
})
export class DonutChart {
  segments = input<DonutSegment[]>([]);
  caption = input<string>('total');

  protected readonly r = 46;
  protected readonly stroke = 16;
  private readonly circumference = 2 * Math.PI * this.r;
  private readonly palette: Tone[] = ['primary', 'info', 'warning', 'success', 'danger', 'secondary'];

  protected readonly total = computed(() => this.segments().reduce((s, x) => s + (x.value || 0), 0));

  /** Segments with resolved colour + percentage. */
  readonly computed = computed(() => {
    const tot = this.total() || 1;
    return this.segments().map((s, i) => ({
      label: s.label,
      value: s.value,
      pct: Math.round((s.value / tot) * 100),
      color: toneVars(s.tone ?? this.palette[i % this.palette.length]).color,
    }));
  });

  protected readonly arcs = computed(() => {
    const tot = this.total() || 1;
    const segs = this.computed().filter(s => s.value > 0);
    // Thin gap between segments for a cleaner, modern donut (none when there's one slice).
    const gap = segs.length > 1 ? 2 : 0;
    let acc = 0;
    return segs.map(s => {
      const frac = s.value / tot;
      const len = Math.max(0.5, frac * this.circumference - gap);
      const dash = `${len.toFixed(2)} ${(this.circumference - len).toFixed(2)}`;
      const offset = (-acc * this.circumference).toFixed(2);
      acc += frac;
      return {color: s.color, dash, offset};
    });
  });
}
