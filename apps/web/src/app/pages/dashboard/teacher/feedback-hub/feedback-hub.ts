import {Component, signal} from '@angular/core';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../common/ui';
import {FeedbackCompose} from '../feedback-compose/feedback-compose';
import {TutorInbox} from '../tutor-inbox/tutor-inbox';

/**
 * Teacher "Feedback & Questions" hub. Give Feedback and Tutor Questions on one
 * tabbed page; each child renders embedded.
 */
@Component({
  selector: 'app-teacher-feedback-hub',
  standalone: true,
  imports: [PageHeader, TabBar, FeedbackCompose, TutorInbox],
  templateUrl: './feedback-hub.html',
})
export class TeacherFeedbackHub {
  readonly tabs: TabItem[] = [
    {key: 'feedback', label: 'Give Feedback'},
    {key: 'questions', label: 'Tutor Questions'},
  ];
  tab = signal<string>('feedback');
}
