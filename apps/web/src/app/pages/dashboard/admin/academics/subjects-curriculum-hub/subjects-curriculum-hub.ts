import {Component, signal} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../../common/ui';
import {Subjects} from '../subjects/subjects';
import {Topics} from '../topics/topics';
import {LessonContent} from '../lesson-content/lesson-content';
import {DeliveryPackDetail} from '../delivery-pack-detail/delivery-pack-detail';
import {SchemeOfWork} from '../scheme-of-work/scheme-of-work';
import {SchemeDetail} from '../scheme-detail/scheme-detail';
import {CurriculumMap} from '../curriculum-map/curriculum-map';
import {ApprovalQueue} from '../approval-queue/approval-queue';

/**
 * School-Admin "Subjects & Curriculum" hub. Unifies the formerly separate
 * Subjects, Topics, Lesson Content, Delivery Pack, Scheme of Work, Scheme
 * Coverage, Curriculum Map and Approval Queue pages into one tabbed page.
 * Each child renders embedded, with its own page header hidden.
 */
@Component({
  selector: 'app-subjects-curriculum-hub',
  standalone: true,
  imports: [PageHeader, TabBar, Subjects, Topics, LessonContent, DeliveryPackDetail, SchemeOfWork, SchemeDetail, CurriculumMap, ApprovalQueue],
  templateUrl: './subjects-curriculum-hub.html',
})
export class SubjectsCurriculumHub {
  readonly tabs: TabItem[] = [
    {key: 'subjects', label: 'Subjects'},
    {key: 'topics', label: 'Topics'},
    {key: 'lessons', label: 'Lesson Content'},
    {key: 'packs', label: 'Delivery Pack'},
    {key: 'scheme', label: 'Scheme of Work'},
    {key: 'coverage', label: 'Scheme Coverage'},
    {key: 'map', label: 'Curriculum Map'},
    {key: 'approvals', label: 'Approval Queue'},
  ];
  tab = signal<string>('subjects');
}
