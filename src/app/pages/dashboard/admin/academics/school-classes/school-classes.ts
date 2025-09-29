import { Component } from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {SchoolClassForm} from '../../../../../components/forms/school-class-form/school-class-form';

@Component({
  selector: 'app-school-classes',
  imports: [
    DataTable,
    LearnoButton,
    PageHeader,
    TableSearch,
    LearnoModal,
    RoutineForm,
    SchoolClassForm
  ],
  templateUrl: './school-classes.html',
  styleUrl: './school-classes.css'
})
export class SchoolClasses {

  clickhandker() {

  }
}
