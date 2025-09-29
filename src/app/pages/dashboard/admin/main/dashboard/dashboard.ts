import { Component } from '@angular/core';
import {UserIntro} from "../../../../../common/user-intro/user-intro";
import {RouterOutlet} from "@angular/router";
import {AppStatCard} from '../../../../../common/app-stat-card/app-stat-card';
import {AttendanceStat} from '../../../../../components/admin/overview/attendance-stat/attendance-stat';
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';

@Component({
  selector: 'app-dashboard',
  imports: [
    UserIntro,
    RouterOutlet,
    AppStatCard,
    AttendanceStat,
    DashboardCard
  ],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css'
})
export class AdminDashboard {

}
