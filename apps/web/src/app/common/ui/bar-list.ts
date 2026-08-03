import {Component, computed, input} from '@angular/core';
import {Tone, toneVars} from './ui-types';

export interface BarItem { label: string; value: number; tone?: Tone; }

/**
 * Horizontal ranked bar list (Top Institutions, Content by Subject). Each row is
 * a label, a proportional bar and its value — CSS bars, no SVG needed.
 */
@Component({
  selector: 'app-bar-list',
  standalone: true,
  template: `
    <ul class="bl">
      @for (i of rows(); track i.label) {
        <li>
          <span class="bl-label" [attr.title]="i.label">{{ i.label }}</span>
          <span class="bl-track"><span class="bl-fill" [style.width.%]="i.pct" [style.background]="i.color"></span></span>
          <span class="bl-value">{{ format(i.value) }}</span>
        </li>
      }
    </ul>
  `,
  styles: [`
    :host { display: block; }
    .bl { list-style: none; margin: 0; padding: 0; }
    .bl li { display: grid; grid-template-columns: minmax(90px, 34%) 1fr auto; align-items: center; gap: .75rem; padding: .3rem 0; }
    .bl-label { font-size: .82rem; color: var(--bs-body-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bl-track { height: 10px; border-radius: 6px; background: var(--bs-tertiary-bg); overflow: hidden; }
    .bl-fill { display: block; height: 100%; border-radius: 6px; transition: width .3s ease; }
    .bl-value { font-size: .82rem; font-weight: 600; color: var(--bs-body-color); text-align: right; min-width: 2.5rem; }
  `],
})
export class BarList {
  items = input<BarItem[]>([]);
  tone = input<Tone>('primary');

  readonly rows = computed(() => {
    const items = this.items();
    const max = Math.max(...items.map(i => i.value), 1);
    return items.map(i => ({
      label: i.label,
      value: i.value,
      pct: Math.max((i.value / max) * 100, 2),
      color: toneVars(i.tone ?? this.tone()).color,
    }));
  });

  format(v: number): string {
    if (v >= 1000) return (v / 1000).toFixed(v % 1000 === 0 ? 0 : 1) + 'k';
    return String(v);
  }
}
