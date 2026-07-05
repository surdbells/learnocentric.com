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

declare const $: any;

@Component({
  selector: 'app-today-class',
  imports: [
    CarouselModule,
    DatePipe,
  ],
  templateUrl: './today-class.html',
  styleUrl: './today-class.css'
})
export class TodayClass implements OnInit, AfterViewInit, OnDestroy{

  @ViewChild('todayCarousel', { static: false }) todayCarousel?: CarouselComponent;
  isLoading = signal<boolean>(false);
  title = input<string>('');

  private dpEl: any;
  private isBrowser: boolean;
  today = (new Date(Date.now())).toISOString();
  todayDayIndex = (new Date(Date.now())).getDay();
  timetable = signal<{[key:number]: any[]}>({});
  virtualClass = signal<{[key:number]: any[]}>({});

  constructor(
    private host: ElementRef<HTMLElement>,
    @Inject(PLATFORM_ID) platformId: Object,
    private readonly apiSrv: ApiService,
    private utilSrv: UtilService,
    private readonly auth: AuthService,
    private readonly  toastSrv: ToastrService
  ) {
    this.isBrowser = isPlatformBrowser(platformId);
  }

  ngOnInit(): void {
        if(this.isBrowser) {
          this.isLoading.set(true);
          const user = this.auth.getAuthSession().user;

            this.apiSrv.get(`/backend/${user?.role}/timetable/${user?.id}/virtual`)
              .subscribe({
                next: (res) => {
                  this.timetable.set(this.utilSrv.groupRoutineToEachDay(res));
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

  todaySlides = [
    { time: '09:00 - 09:45', badgeClass: 'text-bg-danger text-decoration-line-through', class: 'Class V, B', isPast: true },
    { time: '09:00 - 09:45', badgeClass: 'text-bg-danger text-decoration-line-through', class: 'Class IV, C', isPast: true },
    { time: '11:30 - 12:15', badgeClass: 'text-bg-primary', class: 'Class V, B' },
    { time: '01:30 - 02:15', badgeClass: 'text-bg-primary', class: 'Class V, B' },
    { time: '02:15 - 03:00', badgeClass: 'text-bg-primary', class: 'Class V, B' },
  ];

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

  get getTodayTimeTable() {
    return this.timetable()[this.todayDayIndex]?.map((routine) => ({
      time: `${routine.start_time} - ${routine.end_time}`,
      badgeClass: 'text-bg-primary text-decoration-line-through',
      class: `${routine.class_name} - ${routine.subject_name}`,
      isPast: [true, false][Math.floor(Math.random()*2)],
    }))
  }
}
