import {Component, signal} from '@angular/core';
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
  readonly tabs: TabItem[] = [
    {key: 'insights', label: 'Insights'},
    {key: 'analytics', label: 'Analytics'},
    {key: 'report', label: 'School Report'},
    {key: 'cards', label: 'Report Cards'},
  ];
  tab = signal<string>('insights');
}
