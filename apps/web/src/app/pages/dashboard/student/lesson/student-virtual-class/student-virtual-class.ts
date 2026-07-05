import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {DataTable} from '../../../../../components/data-table/data-table';

@Component({
  selector: 'app-student-virtual-class',
  imports: [
    PageHeader,
    DataTable
  ],
  templateUrl: './student-virtual-class.html',
  styleUrl: './student-virtual-class.css'
})
export class StudentVirtualClass {

}
