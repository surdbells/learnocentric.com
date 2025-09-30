import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {DataTable} from '../../../../../components/data-table/data-table';

@Component({
  selector: 'app-student-assessment',
  imports: [
    PageHeader,
    DataTable
  ],
  templateUrl: './student-assessment.html',
  styleUrl: './student-assessment.css'
})
export class StudentAssessment {

}
