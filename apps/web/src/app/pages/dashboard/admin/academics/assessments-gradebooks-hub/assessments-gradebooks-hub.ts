import {Component, signal} from '@angular/core';
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
  readonly tabs: TabItem[] = [
    {key: 'assessments', label: 'Assessments'},
    {key: 'questions', label: 'Question Bank'},
    {key: 'worksheets', label: 'Worksheets'},
    {key: 'portfolio', label: 'Portfolio'},
    {key: 'gradebook', label: 'Gradebook'},
  ];
  tab = signal<string>('assessments');
}
