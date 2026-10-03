import {Component, signal} from '@angular/core';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../common/ui';
import {MyClasses} from '../academics/my-classes/my-classes';
import {Student} from '../academics/student/student';

/**
 * Teacher "My Classes" hub. My Classes and the class learner roster on one
 * tabbed page; each child renders embedded.
 */
@Component({
  selector: 'app-teacher-classes-hub',
  standalone: true,
  imports: [PageHeader, TabBar, MyClasses, Student],
  templateUrl: './classes-hub.html',
})
export class TeacherClassesHub {
  readonly tabs: TabItem[] = [
    {key: 'classes', label: 'Classes'},
    {key: 'learners', label: 'Learners'},
  ];
  tab = signal<string>('classes');
}
