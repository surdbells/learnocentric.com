import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {DataTable} from '../../../../../components/data-table/data-table';

@Component({
  selector: 'app-student-resources',
  imports: [
    PageHeader,
    DataTable
  ],
  templateUrl: './student-resources.html',
  styleUrl: './student-resources.css'
})
export class StudentResources {

}
