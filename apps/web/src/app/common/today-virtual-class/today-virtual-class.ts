import {
  AfterViewInit,
  Component,
  ElementRef,
  Inject, input,
  OnDestroy,
  OnInit,
  PLATFORM_ID,
  signal,
  ViewChild
} from '@angular/core';
import {CarouselComponent, CarouselModule, OwlOptions} from 'ngx-owl-carousel-o';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {ApiService} from '../service/api.service';
import {AuthService} from '../auth/auth.service';
import {UtilService} from '../service/util.service';
import {ToastrService} from 'ngx-toastr';
import {SkeletonLoader} from '../skeleton-loader/skeleton-loader';
import { RouterLink } from "@angular/router";
import { AuthUser } from '../auth/auth.models';

declare const $: any;

@Component({
  selector: 'app-today-virtual-class',
  imports: [
    CarouselModule,
    DatePipe,
],
  templateUrl: './today-virtual-class.html',
  styleUrl: './today-virtual-class.css'
})
export class TodayVirtualClass implements OnInit, AfterViewInit, OnDestroy{

  @ViewChild('todayCarousel', { static: false }) todayCarousel?: CarouselComponent;
  isLoading = signal<boolean>(false);
  title = input<string>('');

  private dpEl: any;
  private isBrowser: boolean;
  today = (new Date(Date.now())).toISOString();
  todayDayIndex = (new Date(Date.now())).getDay();
  virtualClass = signal<any[]>([]);
  user: AuthUser|null = null;

  constructor(
    private host: ElementRef<HTMLElement>,
    @Inject(PLATFORM_ID) platformId: Object,
    private readonly apiSrv: ApiService,
    private utilSrv: UtilService,
    private readonly auth: AuthService,
    private readonly  toastSrv: ToastrService,
  ) {
    this.isBrowser = isPlatformBrowser(platformId);
    if(isPlatformBrowser(platformId)){
      this.user = this.auth.getAuthSession().user;
    }
  }

  ngOnInit(): void {
        if(this.isBrowser) {
          this.isLoading.set(true);
          // const user = this.auth.getAuthSession().user;
          this.apiSrv.get(`${this.user?.role === 'teacher' ? '/backend/virtual-class/teacher' : '/backend/virtual-class/student?classId='+this.user?.classId}`)
            .subscribe({
              next: (res) => {
                this.virtualClass.set(res);
                this.isLoading.set(false);
              },
              error: (err) => {
                this.toastSrv.error(err.error.message, 'Error')
                this.isLoading.set(false);
              },
              complete: () => {
                // console.log('complete')
              }
            })
        }
    }



  todayCarouselOptions: OwlOptions = {
    loop: false,
    dots: false,
    nav: false,
    margin: 15,
    mouseDrag: true,
    touchDrag: true,
    pullDrag: true,
    responsive: {
      0: { items: 1 },
      576: { items: 2 },
      992: { items: 3 },
      1200: { items: 4 }
    }
  };

  ngAfterViewInit(): void {
    if (!this.isBrowser) return;
    try {
      const el = this.host.nativeElement.querySelector('.class-datepick .datetimepicker') as HTMLInputElement | null;
      if (!el || typeof $ === 'undefined' || !$.fn || !$.fn.datetimepicker) return;
      this.dpEl = $(el).datetimepicker({
        format: 'DD MMM YYYY',
        showClear: true,
        showClose: true,
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
      const el = this.host.nativeElement.querySelector('.class-datepick .datetimepicker');
      if (el && typeof $ !== 'undefined' && $.fn && $.fn.datetimepicker) {
        const picker = $(el).data('DateTimePicker');
        if (picker && typeof picker.destroy === 'function') picker.destroy();
      }
    } catch {}
  }

  onPrevToday() {
    this.todayCarousel?.prev()
  }

  onNextToday() {
    this.todayCarousel?.next();
  }

  get getUpcomingVirtualClass() {
    return this.virtualClass()?.map((vt) => ({
      startTime: `${new Date(vt.start_time)}`,
      endTime: `${new Date(vt.end_time)}`,
      className: vt.class_name,
      subjectName: vt.subject_name,
      meetLink: vt.meet_link,
      badgeClass: 'text-bg-primary',
      title: vt.title,
      teacherName: vt.teacher_name,
      organizerEmail: vt.organizer_email,
      isPast: [true, false][Math.floor(Math.random()*2)],
    }))
  }
}
