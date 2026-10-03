import {Component, signal} from '@angular/core';
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
  readonly tabs: TabItem[] = [
    {key: 'assessments', label: 'Assessments'},
    {key: 'questions', label: 'Question Bank'},
    {key: 'worksheets', label: 'Worksheets'},
    {key: 'portfolio', label: 'Portfolio'},
    {key: 'submissions', label: 'Assignments & Submissions'},
    {key: 'gradebook', label: 'Gradebook'},
  ];
  tab = signal<string>('assessments');
}
