import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TableSearch} from '../../../../../components/table-search/table-search';
import {DataTable} from '../../../../../components/data-table/data-table';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {SchoolClassForm} from '../../../../../components/forms/school-class-form/school-class-form';

@Component({
  selector: 'app-assignment',
  imports: [
    PageHeader,
    TableSearch,
    DataTable,
    DataTableNumbering,
    LearnoModal,
    SchoolClassForm
  ],
  templateUrl: './assignment.html',
  styleUrl: './assignment.css'
})
export class Assignment {

}
