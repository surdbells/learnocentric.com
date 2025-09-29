import { Component, AfterViewInit, OnDestroy, ElementRef, Inject, PLATFORM_ID } from '@angular/core';
import { DatePipe, isPlatformBrowser } from '@angular/common';
import { PageHeader } from '../../../../../common/layout/page-header/page-header';
import { UserIntro } from '../../../../../common/user-intro/user-intro';
import { AppStatCard } from '../../../../../common/app-stat-card/app-stat-card';
import { CarouselModule } from 'ngx-owl-carousel-o';
import { SyllabusStat } from '../../../../../components/teacher/syllabus-stat/syllabus-stat';
import { TodayClass } from '../../../../../common/today-class/today-class';
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {EventsListing} from '../../../../../common/events-listing/events-listing';

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
    EventsListing
  ],
  templateUrl: './teacher-dashboard.html',
  styleUrl: './teacher-dashboard.css'
})
export class TeacherDashboard implements AfterViewInit, OnDestroy {
  today = new Date(Date.now()).toISOString();

  private isBrowser: boolean;
  private dpInstance: any;

  schedules = [1,2,3,4]

  constructor(private host: ElementRef<HTMLElement>, @Inject(PLATFORM_ID) platformId: Object) {
    this.isBrowser = isPlatformBrowser(platformId);
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
}
