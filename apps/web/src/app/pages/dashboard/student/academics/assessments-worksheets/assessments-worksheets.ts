import {Component, computed, inject, signal} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../../common/ui';
import {ModuleAccessService} from '../../../../../common/auth/module-access.service';
import {MyAssessments} from '../my-assessments/my-assessments';
import {MyWorksheets} from '../my-worksheets/my-worksheets';

/**
 * Merged learner "Assessments & Worksheets", both graded-work surfaces on one
 * page, switched by a tab. Each child is embedded (its own page header hidden);
 * a tab appears only for a module the learner's plan grants.
 */
@Component({
  selector: 'app-assessments-worksheets',
  standalone: true,
  imports: [PageHeader, TabBar, MyAssessments, MyWorksheets],
  templateUrl: './assessments-worksheets.html',
})
export class AssessmentsWorksheets {
  private readonly modules = inject(ModuleAccessService);

  readonly tabs = computed<TabItem[]>(() => {
    const t: TabItem[] = [];
    if (this.modules.has('assessments')) t.push({key: 'assessments', label: 'Quizzes & Assessments'});
    if (this.modules.has('worksheets')) t.push({key: 'worksheets', label: 'Worksheets'});
    return t;
  });

  tab = signal<string>(this.modules.has('assessments') ? 'assessments' : 'worksheets');
}
