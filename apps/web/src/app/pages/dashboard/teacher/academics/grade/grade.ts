import { Component } from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {LearnoModal} from "../../../../../components/learno-modal/learno-modal";
import {LessonPlanForm} from "../../../../../components/forms/lesson-plan-form/lesson-plan-form";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {GradeForm} from '../../../../../components/forms/grade-form/grade-form';

@Component({
  selector: 'app-grade',
  imports: [
    DataTable,
    LearnoModal,
    LessonPlanForm,
    PageHeader,
    TableSearch,
    GradeForm
  ],
  templateUrl: './grade.html',
  styleUrl: './grade.css'
})
export class Grade {

}
