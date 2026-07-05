import {AfterViewInit, Component, ElementRef, Inject, OnDestroy, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {AppStatCard} from "../../../../../common/app-stat-card/app-stat-card";
import {DashboardCard} from "../../../../../common/dashboard-card/dashboard-card";
import {DatePipe, isPlatformBrowser} from "@angular/common";
import {EventsListing} from "../../../../../common/events-listing/events-listing";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {SyllabusStat} from "../../../../../components/teacher/syllabus-stat/syllabus-stat";
import {TodayClass} from "../../../../../common/today-class/today-class";
import UserIntro from "../../../../../common/user-intro/user-intro";
import {Router, RouterLink} from '@angular/router';
import {ApiService} from '../../../../../common/service/api.service';
import {forkJoin} from 'rxjs';
import {AuthUser} from '../../../../../common/auth/auth.models';
import {AuthService} from '../../../../../common/auth/auth.service';
import {UtilService} from '../../../../../common/service/util.service';
import {Loader} from '../../../../../common/loader/loader';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import { LearnoButton } from "../../../../../common/learno-button/learno-button";
import { TodayVirtualClass } from "../../../../../common/today-virtual-class/today-virtual-class";

declare const $: any;

@Component({
  selector: 'app-student-dashboard',
  imports: [
    AppStatCard,
    DashboardCard,
    DatePipe,
    EventsListing,
    PageHeader,
    SyllabusStat,
    TodayClass,
    UserIntro,
    RouterLink,
    Loader,
    SkeletonLoader,
    LearnoButton,
    TodayVirtualClass
],
  templateUrl: './student-dashboard.html',
  styleUrl: './student-dashboard.css'
})
export class StudentDashboard implements OnInit, AfterViewInit, OnDestroy {
  today = new Date(Date.now()).toISOString();


  isLoading = signal(false);

  schedules = [1,2,3,4,5,6]
  studentCourses: any[] = [];
  student = signal<AuthUser | null>(null);
  private isBrowser: boolean;
  private dpInstance: any;


  constructor(
    private host: ElementRef<HTMLElement>,
    @Inject(PLATFORM_ID) platformId: Object,
    private readonly apiSrv: ApiService,
    private readonly authSrv: AuthService,
    private readonly router: Router,
    private readonly utilSrv: UtilService
  ) {
    this.isBrowser = isPlatformBrowser(platformId);
    this.student.set(authSrv.getAuthSession().user);
  }

  ngOnInit(): void {
    this.isLoading.set(true);
    const user = this.student();
    if(!user) {
      this.router.navigate(['/authentication']);
      return;
    }

    const studentData$ = forkJoin({
      // students: this.apiSrv.get<any[]>('/backend/school/students'),
      courses: this.apiSrv.get<any[]>(`/backend/student/courses/${user.id}`),
    });

    studentData$
      .subscribe({
        next: (data) => {
          this.studentCourses = data.courses;
        },
        error: (error) => {
          this.isLoading.set(false);
          console.error('Error fetching school data:', error);
          // Handle error appropriately
        },
        complete: () => {
          this.isLoading.set(false);
        }
      });
  }


  ngAfterViewInit(): void {
    if (!this.isBrowser) return;
    try {
      const el = this.host.nativeElement.querySelector('#schedule-datepick') as HTMLElement | null;
      if (!el || typeof $ === 'undefined' || !$.fn || !$.fn.datetimepicker) return;
      this.dpInstance = $(el).datetimepicker({
        format: 'DD MMM YYYY',
        inline: true,
        keepOpen: true,
        // showClear: true,
        // showClose: true,
        icons: {
          time: 'mdi mdi-clock-outline',
          date: 'mdi mdi-calendar',
          up: 'mdi mdi-chevron-up',
          down: 'mdi mdi-chevron-down',
          previous: 'mdi mdi-chevron-left',
          next: 'mdi mdi-chevron-right',
          today: 'mdi mdi-calendar-today',
          clear: 'mdi mdi-trash-can-outline',
          close: 'mdi mdi-close'
        }
      });
    } catch {}
  }

  ngOnDestroy(): void {
    if (!this.isBrowser) return;
    try {
      const el = this.host.nativeElement.querySelector('#schedule-datepick');
      if (el && typeof $ !== 'undefined' && $.fn && $.fn.datetimepicker) {
        const picker = $(el).data('DateTimePicker');
        if (picker && typeof picker.destroy === 'function') picker.destroy();
      }
    } catch {}
  }

  get getFullName() : string {
    if(!this.student()) return '';
    return this.utilSrv.getTeacherFullname(this.student()!);
  }
}
