import {Component, computed, input} from '@angular/core';
import {Tone, toneVars} from './ui-types';

/**
 * Compact progress bar + value for table cells (coverage %, completion, x/y).
 * Tone can auto-derive from the value via thresholds, or be set explicitly.
 */
@Component({
  selector: 'app-progress-cell',
  standalone: true,
  template: `
    <div class="pc" [style.--ui-tone]="tv().color" [style.--ui-tone-rgb]="tv().rgb">
      <div class="pc-track"><div class="pc-fill" [style.width.%]="clamped()"></div></div>
      <span class="pc-val">{{ text() || (clamped() + '%') }}</span>
    </div>
  `,
  styles: [`
    :host { display: block; min-width: 90px; }
    .pc { display: flex; align-items: center; gap: .5rem; }
    .pc-track { flex: 1 1 auto; height: 6px; border-radius: 999px; background: rgba(var(--ui-tone-rgb), .15); overflow: hidden; }
    .pc-fill { height: 100%; border-radius: 999px; background: var(--ui-tone); }
    .pc-val { font-size: .76rem; font-weight: 600; color: var(--bs-secondary-color); white-space: nowrap; }
  `],
})
export class ProgressCell {
  value = input<number>(0);       // 0–100
  text = input<string>('');       // optional custom label (e.g. "15/20")
  tone = input<Tone | null>(null);

  protected readonly clamped = computed(() => Math.max(0, Math.min(100, Math.round(this.value()))));
  protected readonly tv = computed(() => {
    if (this.tone()) return toneVars(this.tone());
    const v = this.clamped();
    return toneVars(v >= 70 ? 'success' : v >= 40 ? 'warning' : 'danger');
  });
}
