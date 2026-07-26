import {Component, computed, input} from '@angular/core';
import {Tone, toneVars} from './ui-types';

/**
 * Pill status badge with a tone dot. Either pass an explicit `tone`, or pass a
 * `map` (value→tone) so tables can colour a status column declaratively.
 */
@Component({
  selector: 'app-status-badge',
  standalone: true,
  template: `
    <span class="sb" [style.--ui-tone]="tv().color" [style.--ui-tone-rgb]="tv().rgb">
      <span class="sb-dot"></span>{{ label() || value() }}
    </span>
  `,
  styles: [`
    :host { display: inline-flex; }
    .sb {
      display: inline-flex; align-items: center; gap: .35rem;
      font-size: .75rem; font-weight: 600; white-space: nowrap;
      padding: .18rem .55rem; border-radius: 999px;
      color: var(--ui-tone); background: rgba(var(--ui-tone-rgb), .12);
    }
    .sb-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--ui-tone); }
  `],
})
export class StatusBadge {
  value = input<string>('');
  label = input<string>('');
  tone = input<Tone | null>(null);
  /** value(lowercased) → tone lookup, when tone isn't given directly. */
  map = input<Record<string, Tone>>({});

  protected readonly tv = computed(() => {
    const explicit = this.tone();
    if (explicit) return toneVars(explicit);
    const key = String(this.value()).toLowerCase().replace(/\s+/g, '_');
    return toneVars(this.map()[key] ?? 'secondary');
  });
}
