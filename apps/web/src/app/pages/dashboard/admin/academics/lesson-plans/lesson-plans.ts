import { Component } from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {LessonPlanForm} from '../../../../../components/forms/lesson-plan-form/lesson-plan-form';
import {ActivatedRoute} from '@angular/router';

@Component({
  selector: 'app-lesson-plans',
  imports: [
    DataTable,
    LearnoButton,
    PageHeader,
    TableSearch,
    LearnoModal,
    RoutineForm,
    LessonPlanForm
  ],
  templateUrl: './lesson-plans.html',
  styleUrl: './lesson-plans.css'
})
export class LessonPlans {

  userRole: string;

  constructor(private route: ActivatedRoute) {
    this.userRole = this.route.snapshot.data['user'];
  }

  clickhandker() {

  }
}
