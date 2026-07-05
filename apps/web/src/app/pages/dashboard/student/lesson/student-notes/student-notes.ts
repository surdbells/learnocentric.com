import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {DataTable} from '../../../../../components/data-table/data-table';

@Component({
  selector: 'app-student-notes',
  imports: [
    PageHeader,
    DataTable
  ],
  templateUrl: './student-notes.html',
  styleUrl: './student-notes.css'
})
export class StudentNotes {

}
