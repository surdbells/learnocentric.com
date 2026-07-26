import {Component, OnInit, inject} from '@angular/core';
import {Icon} from '../icon/icon';
import {TermContext} from '../service/term-context';

/**
 * Top-bar academic Session/Term switcher (a global control the designs place in
 * the header). Renders only when the institution has terms — so roles without a
 * term context (e.g. super admin) show nothing. Reads/writes the shared
 * TermContext; screens scope their data off `TermContext.active()`.
 */
@Component({
  selector: 'app-term-switcher',
  standalone: true,
  imports: [Icon],
  template: `
    @if (ctx.terms().length) {
      <div class="dropdown ts">
        <button type="button" class="ts-btn" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
          <app-icon name="calendar_month" [size]="16" class="ts-cal" />
          <span class="ts-label">{{ ctx.active()?.name || 'Select term' }}</span>
          <app-icon name="expand_more" [size]="15" class="ts-caret" />
        </button>
        <ul class="dropdown-menu">
          <li class="ts-heading">Academic term</li>
          @for (t of ctx.terms(); track t.id) {
            <li>
              <button type="button" class="dropdown-item ts-item" [class.active]="t.id === ctx.activeId()"
                      (click)="ctx.setActive(t.id)">
                <span>{{ t.name }}</span>
                @if (t.is_current) { <span class="ts-current">Current</span> }
                @if (t.id === ctx.activeId()) { <app-icon name="check" [size]="15" /> }
              </button>
            </li>
          }
        </ul>
      </div>
    }
  `,
  styles: [`
    :host { display: inline-flex; }
    .ts-btn {
      display: inline-flex; align-items: center; gap: .4rem;
      border: 1px solid var(--bs-border-color); background: var(--bs-body-bg);
      border-radius: 999px; padding: .32rem .7rem; cursor: pointer;
      font-size: .82rem; font-weight: 600; color: var(--bs-body-color);
    }
    .ts-btn:hover { border-color: rgba(var(--brand-rgb), .4); }
    .ts-cal { color: var(--brand-600); }
    .ts-caret { color: var(--bs-secondary-color); }
    .ts-label { white-space: nowrap; }
    .ts-heading { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color); padding: .35rem .9rem .15rem; }
    .ts-item { display: flex; align-items: center; gap: .5rem; font-size: .85rem; }
    .ts-item.active { color: var(--brand-700); font-weight: 600; }
    .ts-current { font-size: .68rem; font-weight: 700; color: var(--brand-700); background: rgba(var(--brand-rgb), .12); border-radius: 999px; padding: .05rem .4rem; margin-left: auto; }
    .ts-item.active .ts-current { margin-left: auto; }
  `],
})
export class TermSwitcher implements OnInit {
  protected readonly ctx = inject(TermContext);
  ngOnInit(): void { this.ctx.load(); }
}
