import { Component } from '@angular/core';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {TableSearch} from '../../../components/table-search/table-search';
import {DataTable} from '../../../components/data-table/data-table';
import {DataTableNumbering} from '../../../components/data-table-numbering/data-table-numbering';

@Component({
  selector: 'app-notication',
  imports: [
    PageHeader,
    TableSearch,
    DataTable,
    DataTableNumbering
  ],
  templateUrl: './notication.html',
  styleUrl: './notication.css'
})
export class Notication {

}
