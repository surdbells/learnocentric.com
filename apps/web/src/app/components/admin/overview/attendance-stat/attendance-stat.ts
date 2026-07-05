import { Component } from '@angular/core';
import {DashboardCard} from '../../../../common/dashboard-card/dashboard-card';

@Component({
  selector: 'app-attendance-stat',
  standalone: true,
  imports: [
    DashboardCard
  ],
  templateUrl: './attendance-stat.html',
  styleUrl: './attendance-stat.css'
})
export class AttendanceStat {

}
