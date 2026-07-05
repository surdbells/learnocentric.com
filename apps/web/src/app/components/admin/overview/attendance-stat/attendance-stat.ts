import { Component } from '@angular/core';
import {DashboardCard} from '../../../../common/dashboard-card/dashboard-card';
import {Icon} from '../../../../common/icon/icon';

@Component({
  selector: 'app-attendance-stat',
  standalone: true,
  imports: [Icon, 
    DashboardCard
  ],
  templateUrl: './attendance-stat.html',
  styleUrl: './attendance-stat.css'
})
export class AttendanceStat {

}
