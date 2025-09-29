import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LessonPlanForm} from '../../../../../components/forms/lesson-plan-form/lesson-plan-form';
import {TableSearch} from '../../../../../components/table-search/table-search';
import {DataTable} from '../../../../../components/data-table/data-table';

@Component({
  selector: 'app-assessment',
  imports: [
    PageHeader,
    LearnoModal,
    LessonPlanForm,
    TableSearch,
    DataTable
  ],
  templateUrl: './assessment.html',
  styleUrl: './assessment.css'
})
export class Assessment {

}
