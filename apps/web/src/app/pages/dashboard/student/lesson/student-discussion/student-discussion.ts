import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {DataTable} from '../../../../../components/data-table/data-table';
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';

@Component({
  selector: 'app-student-discussion',
  imports: [
    PageHeader,
    DataTable,
    DashboardCard
  ],
  templateUrl: './student-discussion.html',
  styleUrl: './student-discussion.css'
})
export class StudentDiscussion {

}
