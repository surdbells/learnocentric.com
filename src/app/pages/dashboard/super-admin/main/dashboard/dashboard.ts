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

  totalInstitutions: number = 0;
  totalSchools: number = 0;
  totalTutoringAcademies: number = 0;
  totalUsers: number = 0;
  totalSubscriptions: number = 0;
  totalContentLibraryItems: number = 0;
  totalContentPackages: number = 0;
  activeUsers: number = 0;

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
          this.totalInstitutions = data?.totalInstitutions ?? data?.institutionsCount ?? (Array.isArray(data?.institutions) ? data.institutions.length : 0);
          this.totalSchools = data?.totalSchools ?? data?.schoolsCount ?? (Array.isArray(data?.schools) ? data.schools.length : 0);
          this.totalTutoringAcademies = data?.totalTutoringAcademies ?? data?.tutoringAcademiesCount ?? (Array.isArray(data?.tutoringAcademies) ? data.tutoringAcademies.length : 0);
          this.totalUsers = data?.totalUsers ?? data?.usersCount ?? (Array.isArray(data?.users) ? data.users.length : 0);
          this.totalSubscriptions = data?.totalSubscriptions ?? data?.subscriptionsCount ?? (Array.isArray(data?.subscriptions) ? data.subscriptions.length : 0);
          this.totalContentLibraryItems = data?.totalContentLibraryItems ?? data?.contentLibraryItemsCount ?? (Array.isArray(data?.contentLibraryItems) ? data.contentLibraryItems.length : 0);
          this.totalContentPackages = data?.totalContentPackages ?? data?.contentPackagesCount ?? (Array.isArray(data?.contentPackages) ? data.contentPackages.length : 0);
          this.activeUsers = data?.activeUsers ?? data?.activeUsersCount ?? (Array.isArray(data?.activeUsers) ? data.activeUsers.length : 0);
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