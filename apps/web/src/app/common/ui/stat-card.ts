import {Component, computed, input} from '@angular/core';
import {RouterLink} from '@angular/router';
import {Icon} from '../icon/icon';
import {SparkBar} from './spark-bar';
import {KpiItem, toneVars} from './ui-types';

/**
 * A single KPI/stat card: tinted icon chip, big value, label, optional signed
 * delta (green up / red down) and a mini sparkline. Whole card is a link when
 * `item.link` is set. Used standalone or composed by <app-kpi-strip>.
 */
@Component({
  selector: 'app-stat-card',
  standalone: true,
  imports: [Icon, SparkBar, RouterLink],
  template: `
    <div class="sc" [class.sc-link]="!!it().link"
         [style.--ui-tone]="tone().color" [style.--ui-tone-rgb]="tone().rgb"
         [attr.role]="it().link ? 'link' : null"
         [routerLink]="it().link || null">
      <div class="sc-top">
        @if (it().icon) {
          <span class="sc-chip"><app-icon [name]="it().icon!" [size]="20" /></span>
        }
        @if (it().spark?.length) {
          <app-spark-bar class="sc-spark" [values]="it().spark!" [tone]="it().tone ?? 'primary'" />
        }
      </div>
      <div class="sc-value">{{ it().value }}</div>
      <div class="sc-label">{{ it().label }}</div>
      @if (it().delta || it().sublabel) {
        <div class="sc-foot">
          @if (it().delta) {
            <span class="sc-delta" [attr.data-dir]="dir()">
              @if (dir() !== 'flat') { <app-icon [name]="dir() === 'up' ? 'arrow_forward' : 'arrow_forward'" [size]="13" class="sc-delta-arrow" [attr.data-dir]="dir()" /> }
              {{ it().delta }}
            </span>
          }
          @if (it().deltaLabel || it().sublabel) {
            <span class="sc-sub">{{ it().deltaLabel || it().sublabel }}</span>
          }
        </div>
      }
    </div>
  `,
  styles: [`
    :host { display: block; }
    .sc {
      background: var(--bs-body-bg);
      border: 1px solid var(--bs-border-color);
      border-radius: var(--bs-border-radius-lg);
      box-shadow: var(--shadow-xs);
      padding: 1.05rem 1.15rem;
      height: 100%;
      display: flex; flex-direction: column;
      transition: box-shadow .18s ease, transform .05s ease, border-color .18s ease;
    }
    .sc-link { cursor: pointer; text-decoration: none; color: inherit; }
    .sc-link:hover { box-shadow: var(--shadow-md); border-color: rgba(var(--ui-tone-rgb), .35); }
    .sc-top { display: flex; align-items: center; justify-content: space-between; min-height: 40px; }
    .sc-chip {
      width: 40px; height: 40px; border-radius: 11px;
      display: inline-flex; align-items: center; justify-content: center;
      color: var(--ui-tone); background: rgba(var(--ui-tone-rgb), .12);
    }
    .sc-spark { opacity: .9; }
    .sc-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; margin-top: .55rem; color: var(--bs-emphasis-color); }
    .sc-label { font-size: .84rem; color: var(--bs-secondary-color); margin-top: .15rem; }
    .sc-foot { display: flex; align-items: center; gap: .4rem; margin-top: .6rem; font-size: .78rem; }
    .sc-delta { font-weight: 600; display: inline-flex; align-items: center; gap: .15rem; }
    .sc-delta[data-dir="up"] { color: var(--bs-success); }
    .sc-delta[data-dir="down"] { color: var(--bs-danger); }
    .sc-delta[data-dir="flat"] { color: var(--bs-secondary-color); }
    .sc-delta-arrow[data-dir="up"] { transform: rotate(-45deg); }
    .sc-delta-arrow[data-dir="down"] { transform: rotate(45deg); }
    .sc-sub { color: var(--bs-secondary-color); }
  `],
})
export class StatCard {
  item = input.required<KpiItem>();
  protected readonly it = computed(() => this.item());
  protected readonly tone = computed(() => toneVars(this.item().tone));
  protected readonly dir = computed(() => this.item().deltaDir ?? 'flat');
}
