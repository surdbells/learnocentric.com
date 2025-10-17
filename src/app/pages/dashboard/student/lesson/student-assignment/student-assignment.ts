import { Component } from '@angular/core';
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {DataTable} from '../../../../../components/data-table/data-table';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';

@Component({
  selector: 'app-student-assignment',
  imports: [
    DashboardCard,
    DataTable,
    PageHeader
  ],
  templateUrl: './student-assignment.html',
  styleUrl: './student-assignment.css'
})
export class StudentAssignment {

}
