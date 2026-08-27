import {Component, computed, input} from '@angular/core';
import {Tone, toneVars} from './ui-types';

/**
 * SVG progress ring (single value 0–100) with a centred value + optional caption.
 * Self-contained, no chart library. Used for "Overall Progress", completion
 * rates, mastery, etc. throughout the designs.
 */
@Component({
  selector: 'app-stat-ring',
  standalone: true,
  template: `
    <div class="ring" [style.--ui-tone]="tv().color" [style.--ring-fs.px]="size() * 0.27" [style.width.px]="size()">
      <svg [attr.width]="size()" [attr.height]="size()" [attr.viewBox]="'0 0 ' + size() + ' ' + size()">
        <circle [attr.cx]="c()" [attr.cy]="c()" [attr.r]="r()" fill="none"
                stroke="color-mix(in srgb, var(--ui-tone) 15%, transparent)" [attr.stroke-width]="stroke()" />
        @if (value() > 0) {
          <circle [attr.cx]="c()" [attr.cy]="c()" [attr.r]="r()" fill="none"
                  stroke="var(--ui-tone)" [attr.stroke-width]="stroke()" stroke-linecap="round"
                  [attr.stroke-dasharray]="circ()" [attr.stroke-dashoffset]="offset()"
                  [attr.transform]="'rotate(-90 ' + c() + ' ' + c() + ')'" />
        }
      </svg>
      <div class="ring-center">
        <span class="ring-value">{{ display() }}</span>
        @if (caption()) { <span class="ring-cap">{{ caption() }}</span> }
      </div>
    </div>
  `,
  styles: [`
    :host { display: inline-block; }
    .ring { position: relative; display: inline-grid; place-items: center; }
    .ring svg { display: block; }
    .ring-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .ring-value { font-size: var(--ring-fs, 1.15rem); font-weight: 700; color: var(--bs-emphasis-color); line-height: 1; letter-spacing: -.02em; }
    .ring-cap { font-size: calc(var(--ring-fs, 1rem) * .42); color: var(--bs-secondary-color); margin-top: .15rem; }
  `],
})
export class StatRing {
  /** 0–100 */
  value = input<number>(0);
  label = input<string>('');       // explicit centre text (overrides "{value}%")
  caption = input<string>('');
  tone = input<Tone>('primary');
  size = input<number>(96);
  stroke = input<number>(9);

  protected readonly tv = computed(() => toneVars(this.tone()));
  protected readonly c = computed(() => this.size() / 2);
  protected readonly r = computed(() => this.size() / 2 - this.stroke() / 2 - 1);
  protected readonly circ = computed(() => 2 * Math.PI * this.r());
  protected readonly offset = computed(() => {
    const pct = Math.max(0, Math.min(100, this.value()));
    return this.circ() * (1 - pct / 100);
  });
  protected readonly display = computed(() => this.label() || `${Math.round(this.value())}%`);
}
