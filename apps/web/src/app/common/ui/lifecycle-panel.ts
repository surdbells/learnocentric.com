import {Component, computed, input, output} from '@angular/core';
import {Icon} from '../icon/icon';

/** One step the viewer could take, as the API describes it. */
export interface LifecycleStep {
  to: string;
  action: string;
  label: string;
  allowed: boolean;
  reason?: string | null;
  /** Filled in here when the step asks for one (returning an item to its author). */
  note?: string | null;
}

/** The API's account of where an item stands for this viewer (LifecycleService::guidance). */
export interface LifecycleGuidance {
  status: string;
  status_label: string;
  summary: string;
  workflow: string;
  can_edit: boolean;
  edit_reason?: string | null;
  blockers?: string[];
  actions: LifecycleStep[];
}

/** Step names used only when the API sends no guidance. */
const PLAIN_LABEL: Record<string, string> = {
  review: 'Submit for review', approved: 'Approve', published: 'Publish', archived: 'Archive', draft: 'Return to draft',
};

/** What to tell the person once a step has gone through, by the status the item ended in. */
export function lifecycleDone(status: string | undefined, noun: string): string {
  const what = noun.charAt(0).toUpperCase() + noun.slice(1);
  switch (status) {
    case 'review': return `${what} submitted for review. The approvers have been notified.`;
    case 'approved': return `${what} approved. It still needs publishing before learners can use it.`;
    case 'published': return `${what} published.`;
    case 'archived': return `${what} archived. Learners can no longer see it.`;
    case 'draft': return `${what} is back in draft and can be edited again.`;
    default: return `${what} updated.`;
  }
}

/**
 * Where a piece of content stands and what the viewer can do next.
 *
 * Renders the server's guidance as is: the status in a sentence, why the item is
 * locked when it is, what still stops it being published, and every next step,
 * with the reason beside any step this viewer cannot take. The page decides
 * nothing about roles; it only carries out the step that is emitted.
 */
@Component({
  selector: 'app-lifecycle-panel',
  standalone: true,
  imports: [Icon],
  template: `
    @if (guide(); as l) {
      <div class="life-panel life-{{ l.status }}" role="status" [class.d-none]="!l.summary">
        @if (l.summary) { <div class="fw-semibold">{{ l.summary }}</div> }
        @if (!l.can_edit && l.edit_reason) {
          <div class="small mt-2">
            <span class="fw-semibold"><app-icon name="lock" [size]="14" /> Why you can’t edit it:</span>
            {{ l.edit_reason }}
          </div>
        }
        @if (showBlockers() && l.blockers?.length && l.status !== 'published' && l.status !== 'archived') {
          <div class="small mt-2">
            <span class="fw-semibold">Before it can be published:</span>
            @for (b of l.blockers; track b) { <span class="d-block">{{ b }}</span> }
          </div>
        }
      </div>

      <div class="mt-3">
        <div class="form-label text-uppercase small fw-semibold text-body-secondary">Next step</div>
        @if (l.actions.length) {
          <div class="d-flex flex-wrap gap-2">
            @for (act of l.actions; track act.to) {
              <button type="button" class="btn btn-sm"
                      [class.btn-primary]="act.to !== 'archived' && act.action !== 'return'"
                      [class.btn-outline-secondary]="act.action === 'return'"
                      [class.btn-outline-danger]="act.to === 'archived'"
                      [disabled]="!act.allowed || busy()"
                      [attr.aria-describedby]="!act.allowed ? uid + '-' + act.to : null"
                      (click)="choose(act)">
                {{ act.label }}
              </button>
            }
          </div>
          @if (blocked(l).length) {
            <ul class="list-unstyled small text-body-secondary mt-2 mb-0">
              @for (act of blocked(l); track act.to) {
                <li [id]="uid + '-' + act.to"><span class="fw-semibold">{{ act.label }}:</span> {{ act.reason }}</li>
              }
            </ul>
          }
        } @else {
          <div class="text-body-secondary small">No further steps are available from this state.</div>
        }
      </div>
    }
  `,
  styles: [`
    :host { display: block; }
    .life-panel {
      border: 1px solid var(--bs-border-color);
      border-left-width: 4px;
      border-radius: .5rem;
      padding: .75rem 1rem;
      background: var(--bs-tertiary-bg);
    }
    .life-panel.life-review { border-left-color: var(--bs-warning); }
    .life-panel.life-approved { border-left-color: var(--bs-info); }
    .life-panel.life-published { border-left-color: var(--bs-success); }
    .life-panel.life-archived { border-left-color: var(--bs-secondary); }
  `],
})
export class LifecyclePanel {
  private static seq = 0;
  /** Unique prefix so the reasons can be tied to their buttons for screen readers. */
  readonly uid = 'life' + (++LifecyclePanel.seq);

  readonly life = input<LifecycleGuidance | null | undefined>(null);
  /**
   * The item's reachable statuses, used only when the API has not sent guidance
   * (an older API version): the steps are then listed plainly and the server
   * decides when one is chosen.
   */
  readonly nextStates = input<string[] | null | undefined>(null);

  readonly guide = computed<LifecycleGuidance | null>(() => {
    const l = this.life();
    if (l) return l;
    const next = this.nextStates();
    if (!next?.length) return null;
    return {
      status: '', status_label: '', summary: '', workflow: '', can_edit: true, blockers: [],
      actions: next.map((to) => ({to, action: to === 'draft' ? 'restore' : to, label: PLAIN_LABEL[to] ?? to, allowed: true})),
    };
  });
  readonly busy = input<boolean>(false);
  /** Set false where the page already explains what blocks publication (e.g. the assessment builder). */
  readonly showBlockers = input<boolean>(true);

  /** The step the viewer chose. Only steps the server allows are emitted. */
  readonly step = output<LifecycleStep>();

  blocked(l: LifecycleGuidance): LifecycleStep[] {
    return l.actions.filter((a) => !a.allowed && a.reason);
  }

  choose(act: LifecycleStep): void {
    if (!act.allowed) return;
    if (act.action === 'return' && typeof window !== 'undefined') {
      const note = window.prompt('Reason for returning it to the author (optional):');
      if (note === null) return; // cancelled: leave the item where it is
      this.step.emit({...act, note: note.trim() || null});
      return;
    }
    this.step.emit(act);
  }
}
