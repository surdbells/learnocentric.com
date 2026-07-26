import {Component, computed, input} from '@angular/core';

/**
 * Avatar (image or initials) + name + optional sub-line, for the identity
 * column of the design's rich tables (learners, teachers, tickets, etc.).
 */
@Component({
  selector: 'app-avatar-cell',
  standalone: true,
  template: `
    <div class="av">
      @if (image()) {
        <img class="av-img" [src]="image()" [alt]="name()" />
      } @else {
        <span class="av-fallback">{{ initials() }}</span>
      }
      <span class="av-meta">
        <span class="av-name">{{ name() }}</span>
        @if (sub()) { <span class="av-sub">{{ sub() }}</span> }
      </span>
    </div>
  `,
  styles: [`
    :host { display: inline-flex; }
    .av { display: inline-flex; align-items: center; gap: .55rem; min-width: 0; }
    .av-img, .av-fallback {
      width: 34px; height: 34px; flex: none; border-radius: 50%;
      object-fit: cover; background: rgba(var(--brand-rgb), .14);
    }
    .av-fallback {
      display: inline-flex; align-items: center; justify-content: center;
      font-size: .72rem; font-weight: 700; color: var(--brand-700); text-transform: uppercase;
    }
    .av-meta { display: flex; flex-direction: column; min-width: 0; }
    .av-name { font-size: .85rem; font-weight: 600; color: var(--bs-emphasis-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .av-sub { font-size: .74rem; color: var(--bs-secondary-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  `],
})
export class AvatarCell {
  name = input<string>('');
  sub = input<string>('');
  image = input<string | null>(null);

  protected readonly initials = computed(() => {
    const parts = this.name().trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
  });
}
