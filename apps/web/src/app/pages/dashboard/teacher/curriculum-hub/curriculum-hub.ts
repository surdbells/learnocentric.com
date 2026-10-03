import {Component, signal} from '@angular/core';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../common/ui';
import {Topics} from '../../admin/academics/topics/topics';
import {LessonContent} from '../../admin/academics/lesson-content/lesson-content';
import {DeliveryPackDetail} from '../../admin/academics/delivery-pack-detail/delivery-pack-detail';
import {SchemeOfWork} from '../../admin/academics/scheme-of-work/scheme-of-work';
import {SchemeDetail} from '../../admin/academics/scheme-detail/scheme-detail';
import {CurriculumMap} from '../../admin/academics/curriculum-map/curriculum-map';
import {ApprovalQueue} from '../../admin/academics/approval-queue/approval-queue';

/**
 * Teacher "Subjects & Curriculum" hub. The shared curriculum pages on one
 * tabbed page (no Subjects catalogue, which teachers don't manage); each
 * child renders embedded.
 */
@Component({
  selector: 'app-teacher-curriculum-hub',
  standalone: true,
  imports: [PageHeader, TabBar, Topics, LessonContent, DeliveryPackDetail, SchemeOfWork, SchemeDetail, CurriculumMap, ApprovalQueue],
  templateUrl: './curriculum-hub.html',
})
export class TeacherCurriculumHub {
  readonly tabs: TabItem[] = [
    {key: 'topics', label: 'Topics'},
    {key: 'lessons', label: 'Lesson Content'},
    {key: 'packs', label: 'Delivery Pack'},
    {key: 'scheme', label: 'Scheme of Work'},
    {key: 'coverage', label: 'Scheme Coverage'},
    {key: 'map', label: 'Curriculum Map'},
    {key: 'approvals', label: 'Approval Queue'},
  ];
  tab = signal<string>('topics');
}
