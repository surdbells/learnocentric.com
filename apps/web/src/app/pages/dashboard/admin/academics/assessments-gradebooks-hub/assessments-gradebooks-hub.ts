import {Component, computed, effect, inject, signal} from '@angular/core';
import {ModuleAccessService} from '../../../../../common/auth/module-access.service';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../../common/ui';
import {Assessments} from '../assessments/assessments';
import {QuestionBank} from '../question-bank/question-bank';
import {Worksheets} from '../worksheets/worksheets';
import {Portfolio} from '../portfolio/portfolio';
import {Gradebook} from '../gradebook/gradebook';

/**
 * School-Admin "Assessments & Gradebooks" hub. Unifies Assessments, Question
 * Bank, Worksheets, Portfolio and Gradebook into one tabbed page; each child
 * renders embedded, with its own page header collapsed to its actions.
 */
@Component({
  selector: 'app-assessments-gradebooks-hub',
  standalone: true,
  imports: [PageHeader, TabBar, Assessments, QuestionBank, Worksheets, Portfolio, Gradebook],
  templateUrl: './assessments-gradebooks-hub.html',
})
export class AssessmentsGradebooksHub {
  private readonly access = inject(ModuleAccessService);
  /** Tabs the school's plan includes and this role may view. */
  readonly tabs = computed<TabItem[]>(() => this.access.visible([
    {key: 'assessments', label: 'Assessments', module: 'assessments', perm: 'assessment'},
    {key: 'questions', label: 'Question Bank', module: 'assessments', perm: 'assessment'},
    {key: 'worksheets', label: 'Worksheets', module: 'worksheets', perm: 'worksheet'},
    {key: 'portfolio', label: 'Portfolio', module: 'portfolio', perm: 'portfolio'},
    {key: 'gradebook', label: 'Gradebook', module: 'assessments', perm: 'gradebook'},
  ]));
  tab = signal<string>('assessments');

  constructor() {
    // If the open tab isn't available (plan or role), fall back to the first one that is.
    effect(() => {
      const keys = this.tabs().map(t => t.key);
      if (keys.length && !keys.includes(this.tab())) this.tab.set(keys[0]);
    });
  }
}
