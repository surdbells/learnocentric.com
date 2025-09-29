import { Component } from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {SubjectForm} from '../../../../../components/forms/subject-form/subject-form';

@Component({
  selector: 'app-subjects',
  imports: [
    DataTable,
    LearnoButton,
    PageHeader,
    TableSearch,
    LearnoModal,
    RoutineForm,
    SubjectForm
  ],
  templateUrl: './subjects.html',
  styleUrl: './subjects.css'
})
export class Subjects {

  clickhandker() {

  }
}
