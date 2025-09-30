import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {DataTable} from '../../../../../components/data-table/data-table';
import {TableSearch} from '../../../../../components/table-search/table-search';

@Component({
  selector: 'app-student-performance',
  imports: [
    PageHeader,
    DataTable,
    TableSearch
  ],
  templateUrl: './student-performance.html',
  styleUrl: './student-performance.css'
})
export class StudentPerformance {

}
