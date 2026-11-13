import {Component, computed, effect, inject, signal} from '@angular/core';
import {ModuleAccessService} from '../../../../../common/auth/module-access.service';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../../common/ui';
import {Insights} from '../insights/insights';
import {Analytics} from '../analytics/analytics';
import {SchoolReport} from '../school-report/school-report';
import {ReportCards} from '../report-cards/report-cards';

/**
 * School-Admin "Reports & Report Cards" hub. Unifies Insights, Analytics, the
 * School Performance Report and Report Cards into one tabbed page; each child
 * renders embedded.
 */
@Component({
  selector: 'app-reports-hub',
  standalone: true,
  imports: [PageHeader, TabBar, Insights, Analytics, SchoolReport, ReportCards],
  templateUrl: './reports-hub.html',
})
export class ReportsHub {
  private readonly access = inject(ModuleAccessService);
  /** Tabs the school's plan includes and this role may view. */
  readonly tabs = computed<TabItem[]>(() => this.access.visible([
    {key: 'insights', label: 'Insights', module: 'assessments', perm: 'assessment'},
    {key: 'analytics', label: 'Analytics', module: 'analytics', perm: 'report'},
    {key: 'report', label: 'School Report', module: 'analytics', perm: 'report'},
    {key: 'cards', label: 'Report Cards', module: 'analytics', perm: 'gradebook'},
  ]));
  tab = signal<string>('insights');

  constructor() {
    // If the open tab isn't available (plan or role), fall back to the first one that is.
    effect(() => {
      const keys = this.tabs().map(t => t.key);
      if (keys.length && !keys.includes(this.tab())) this.tab.set(keys[0]);
    });
  }
}
