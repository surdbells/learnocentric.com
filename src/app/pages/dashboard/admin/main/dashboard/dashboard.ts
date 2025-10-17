import {Component, OnInit, signal} from '@angular/core';
import UserIntro from "../../../../../common/user-intro/user-intro";
import {RouterOutlet} from "@angular/router";
import {AppStatCard} from '../../../../../common/app-stat-card/app-stat-card';
import {AttendanceStat} from '../../../../../components/admin/overview/attendance-stat/attendance-stat';
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {AuthSession} from '../../../../../common/auth/auth.models';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {forkJoin, Subject, takeUntil} from 'rxjs';
import {Loader} from '../../../../../common/loader/loader';

@Component({
  selector: 'app-dashboard',
  imports: [
    UserIntro,
    RouterOutlet,
    AppStatCard,
    AttendanceStat,
    DashboardCard,
    Loader
  ],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css'
})
export class AdminDashboard implements OnInit {

  readonly user = signal<AuthSession | null>(null);
  isLoading = signal(false);
  // public destroy$ = new Subject<void>();

  classesCount: number = 0;
  subjectsCount: number = 0;
  studentsCount: number = 0;
  teachersCount: number = 0;

  constructor(
    private readonly authService: AuthService,
    private readonly apiSrv: ApiService,
    // private readonly router: Router
  ) {
    this.user.set(this.authService.getAuthSession());
  }

  ngOnInit(): void {
    this.isLoading.set(true);
    const schoolData$ = forkJoin({
      classes: this.apiSrv.get<any[]>('/backend/school/classes'),
      subjects: this.apiSrv.get<any[]>('/backend/school/subjects'),
      students: this.apiSrv.get<any[]>('/backend/school/students'),
      teachers: this.apiSrv.get<any[]>('/backend/school/teachers')
    });

    schoolData$
    //   .pipe(
    //   takeUntil(this.destroy$)
    // )
      .subscribe({
      next: (data) => {
        this.classesCount = data.classes.length;
        this.subjectsCount = data.subjects.length;
        this.studentsCount = data.students.length;
        this.teachersCount = data.teachers.length;
      },
      error: (error) => {
        console.error('Error fetching school data:', error);
        // Handle error appropriately
      },
        complete: () => {
        this.isLoading.set(false);
        }
    });
  }


}
