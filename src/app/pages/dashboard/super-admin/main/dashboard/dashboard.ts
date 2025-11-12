import {Component, OnInit, signal} from '@angular/core';
import UserIntro from "../../../../../common/user-intro/user-intro";
import {RouterLink, RouterOutlet} from "@angular/router";
import {AppStatCard} from '../../../../../common/app-stat-card/app-stat-card';
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {AuthSession} from '../../../../../common/auth/auth.models';
import {AuthService} from '../../../../../common/auth/auth.service';
import {Loader} from '../../../../../common/loader/loader';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {DatePipe} from '@angular/common';
import {ApiService} from '../../../../../common/service/api.service';
import { ToastrService } from 'ngx-toastr';

@Component({
  selector: 'app-super-admin-dashboard',
  standalone: true,
  imports: [
    UserIntro,
    RouterOutlet,
    AppStatCard,
    DashboardCard,
    Loader,
    SkeletonLoader,
    RouterLink,
    DatePipe
  ],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css'
})
export class SuperAdminDashboard implements OnInit {
  readonly user = signal<AuthSession | null>(null);
  isLoading = signal(false);

  institutionsCount: number = 0;
  adminsCount: number = 0;
  teachersCount: number = 0;
  studentsCount: number = 0;

  constructor(
    private readonly authService: AuthService,
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService
  ) {
    this.user.set(this.authService.getAuthSession());
  }

  ngOnInit(): void {
    this.isLoading.set(true);
    this.apiSrv.get<any>('/backend/admin/stats')
      .subscribe({
        next: (data) => {
          // Attempt to read common fields; fallback to alternative keys or array lengths
          this.institutionsCount = data?.totalInstitutions ?? data?.institutionsCount ?? (Array.isArray(data?.institutions) ? data.institutions.length : 0);
          this.adminsCount = data?.totalAdmins ?? data?.adminsCount ?? (Array.isArray(data?.admins) ? data.admins.length : 0);
          this.teachersCount = data?.totalTeachers ?? data?.teachersCount ?? (Array.isArray(data?.teachers) ? data.teachers.length : 0);
          this.studentsCount = data?.totalStudents ?? data?.studentsCount ?? (Array.isArray(data?.students) ? data.students.length : 0);
        },
        error: (error) => {
          this.isLoading.set(false)
          this.toastSrv.error("unable to load stat data")
          console.error('Error fetching platform statistics:', error);
        },
        complete: () => {
          this.isLoading.set(false);
        }
      });
  }

  get getTodayDate(): string {
    return (new Date()).toISOString()
  }
}