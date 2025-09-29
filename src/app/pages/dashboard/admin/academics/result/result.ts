import { Component } from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {DataTable} from "../../../../../components/data-table/data-table";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';

@Component({
  selector: 'app-result',
  imports: [
    PageHeader,
    DataTable,
    TableSearch,
    LearnoModal,
    RoutineForm
  ],
  templateUrl: './result.html',
  styleUrl: './result.css'
})
export class Result {

}
