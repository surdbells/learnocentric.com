import {Component, computed, inject, signal} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../../common/ui';
import {ModuleAccessService} from '../../../../../common/auth/module-access.service';
import {ProgressReport} from '../progress-report/progress-report';
import {MyFeedback} from '../my-feedback/my-feedback';

/**
 * Merged learner "Progress & Feedback", the performance overview and tutor
 * feedback on one page, switched by a tab. Progress is analytics-gated; Feedback
 * is always available. Each child is embedded (its own page header hidden).
 */
@Component({
  selector: 'app-progress-feedback',
  standalone: true,
  imports: [PageHeader, TabBar, ProgressReport, MyFeedback],
  templateUrl: './progress-feedback.html',
})
export class ProgressFeedback {
  private readonly modules = inject(ModuleAccessService);

  readonly tabs = computed<TabItem[]>(() => {
    const t: TabItem[] = [];
    if (this.modules.has('analytics')) t.push({key: 'progress', label: 'Progress'});
    t.push({key: 'feedback', label: 'Feedback'});
    return t;
  });

  tab = signal<string>(this.modules.has('analytics') ? 'progress' : 'feedback');
}
