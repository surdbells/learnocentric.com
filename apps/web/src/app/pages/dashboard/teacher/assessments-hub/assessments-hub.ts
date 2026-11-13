import {Component, computed, effect, inject, signal} from '@angular/core';
import {ModuleAccessService} from '../../../../common/auth/module-access.service';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../common/ui';
import {Assessments} from '../../admin/academics/assessments/assessments';
import {QuestionBank} from '../../admin/academics/question-bank/question-bank';
import {Worksheets} from '../../admin/academics/worksheets/worksheets';
import {Portfolio} from '../../admin/academics/portfolio/portfolio';
import {Gradebook} from '../../admin/academics/gradebook/gradebook';
import {SubmissionsInbox} from '../submissions-inbox/submissions-inbox';

/**
 * Teacher "Assessments & Gradebooks" hub. Assessments, Question Bank,
 * Worksheets, Portfolio, the Assignments & Submissions inbox and Gradebook on
 * one tabbed page; each child renders embedded.
 */
@Component({
  selector: 'app-teacher-assessments-hub',
  standalone: true,
  imports: [PageHeader, TabBar, Assessments, QuestionBank, Worksheets, Portfolio, Gradebook, SubmissionsInbox],
  templateUrl: './assessments-hub.html',
})
export class TeacherAssessmentsHub {
  private readonly access = inject(ModuleAccessService);
  /** Tabs the school's plan includes and this role may view. */
  readonly tabs = computed<TabItem[]>(() => this.access.visible([
    {key: 'assessments', label: 'Assessments', module: 'assessments', perm: 'assessment'},
    {key: 'questions', label: 'Question Bank', module: 'assessments', perm: 'assessment'},
    {key: 'worksheets', label: 'Worksheets', module: 'worksheets', perm: 'worksheet'},
    {key: 'portfolio', label: 'Portfolio', module: 'portfolio', perm: 'portfolio'},
    {key: 'submissions', label: 'Assignments & Submissions', module: 'worksheets', perm: 'worksheet'},
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
