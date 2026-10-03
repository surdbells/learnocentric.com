import {Component, signal} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../../common/ui';
import {ClassesLearners} from '../classes-learners/classes-learners';
import {Students} from '../../student/students/students';
import {Enrollment} from '../../student/enrollment/enrollment';

/**
 * School-Admin "Classes & Learners" hub. Consolidates the previously separate
 * Classes & Learners, Students and Enrollment pages into one tabbed page
 * (PDF review: "Merge Students & Enrollment", fold the Class page in). Each
 * child renders embedded, with its own page header hidden.
 */
@Component({
  selector: 'app-classes-hub',
  standalone: true,
  imports: [PageHeader, TabBar, ClassesLearners, Students, Enrollment],
  templateUrl: './classes-hub.html',
})
export class ClassesHub {
  readonly tabs: TabItem[] = [
    {key: 'classes', label: 'Classes & Learners'},
    {key: 'students', label: 'Students'},
    {key: 'enrollment', label: 'Enrollment'},
  ];
  tab = signal<string>('classes');
}
