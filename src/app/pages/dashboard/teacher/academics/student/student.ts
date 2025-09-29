import { Component } from '@angular/core';
import {DataTable} from '../../../../../components/data-table/data-table';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TableSearch} from '../../../../../components/table-search/table-search';

@Component({
  selector: 'app-student',
  imports: [
    DataTable,
    DataTableNumbering,
    LearnoButton,
    PageHeader,
    TableSearch
  ],
  templateUrl: './student.html',
  styleUrl: './student.css'
})
export class Student {

  clickedHandler() {

  }
}
