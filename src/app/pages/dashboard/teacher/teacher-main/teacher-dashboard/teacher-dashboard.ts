import {Component, AfterViewInit, OnDestroy, ElementRef, Inject, PLATFORM_ID, OnInit, signal} from '@angular/core';
import { DatePipe, isPlatformBrowser, NgOptimizedImage } from '@angular/common';
import { PageHeader } from '../../../../../common/layout/page-header/page-header';
import UserIntro from '../../../../../common/user-intro/user-intro';
import { AppStatCard } from '../../../../../common/app-stat-card/app-stat-card';
import { CarouselModule } from 'ngx-owl-carousel-o';
import { SyllabusStat } from '../../../../../components/teacher/syllabus-stat/syllabus-stat';
import { TodayClass } from '../../../../../common/today-class/today-class';
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {EventsListing} from '../../../../../common/events-listing/events-listing';
import {ApiService} from '../../../../../common/service/api.service';
import {forkJoin} from 'rxjs';
import {Loader} from '../../../../../common/loader/loader';
import {AuthService} from '../../../../../common/auth/auth.service';
import { Router, RouterLink } from '@angular/router';
import {AuthUser} from '../../../../../common/auth/auth.models';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import { LearnoButton } from "../../../../../common/learno-button/learno-button";
import { TodayVirtualClass } from "../../../../../common/today-virtual-class/today-virtual-class";

declare const $: any;

@Component({
  selector: 'app-teacher-dashboard',
  imports: [
    PageHeader,
    UserIntro,
    AppStatCard,
    CarouselModule,
    SyllabusStat,
    TodayClass,
    DatePipe,
    DashboardCard,
    EventsListing,
    Loader,
    SkeletonLoader,
    LearnoButton,
    RouterLink,
    TodayVirtualClass,
    NgOptimizedImage
],
  templateUrl: './teacher-dashboard.html',
  styleUrl: './teacher-dashboard.css'
})
export class TeacherDashboard implements OnInit, AfterViewInit, OnDestroy {
  today = new Date(Date.now()).toISOString();

  private isBrowser: boolean;
  private dpInstance: any;


  schedules = [1,2,3,4]

  isLoading = signal(false);
  // public destroy$ = new Subject<void>();

  studentsCount: number = 0
  teacherClasses: any[] = [];
  teacher: AuthUser | null= null
  // teacherSubject = signal<any[]>([]);
  // teacherEvents = signal<any[]>([]);
  // teacherSyllabus = signal<any[]>([]);

  constructor(
    private host: ElementRef<HTMLElement>,
    @Inject(PLATFORM_ID) platformId: Object,
    private readonly apiSrv: ApiService,
    private readonly authSrv: AuthService,
    private readonly router: Router
  ) {
    this.isBrowser = isPlatformBrowser(platformId);
    this.teacher = authSrv.getAuthSession().user
  }

  ngOnInit(): void {
    this.isLoading.set(true);
    const user = this.teacher;
    if(!user) {
      this.router.navigate(['/authentication']);
      return;
    }

    const schoolData$ = forkJoin({
      students: this.apiSrv.get<any[]>('/backend/school/students'),
      teacherClasses: this.apiSrv.get<any[]>(`/backend/teacher/classes/${user.id}`),
    });

    schoolData$
      .subscribe({
        next: (data) => {
          this.studentsCount = data.students.length;
          this.teacherClasses = data.teacherClasses;
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

  getTecherClasses(): string {
    return this.teacherClasses?.map(c => c.name).join(', ');
  }

  getTotalStudentsCount(): number {
    return this.teacherClasses.reduce((acc, c) => acc + c.student_count, 0);
  }

  get getTeacherFullname(): string {
    if(!this.teacher) return '';
    return this.teacher["firstName"] + ' ' + this.teacher["lastName"];
  }
}
