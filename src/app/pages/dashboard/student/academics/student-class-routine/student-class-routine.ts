import { Component, Inject, PLATFORM_ID, signal, ViewChild } from '@angular/core';
import {AuthUser} from '../../../../../common/auth/auth.models';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {UtilService} from '../../../../../common/service/util.service';
import { ToastrService } from "ngx-toastr";
import { isPlatformBrowser } from "@angular/common";
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {RoutineDayCard} from '../../../../../components/routine-day-card/routine-day-card';
import {RoutineCard} from '../../../../../components/routine-card/routine-card';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';

@Component({
  selector: 'app-student-class-routine',
  imports: [
    SkeletonLoader,
    DashboardCard,
    RoutineDayCard,
    RoutineCard,
    PageHeader,
    LearnoOffset
  ],
  // standalone: true,
  templateUrl: './student-class-routine.html',
  styleUrl: './student-class-routine.css'
})
export class StudentClassRoutine {
  isLoading = signal(false);
  days = [
    { label: 'Monday', value: '1' },
    { label: 'Tuesday', value: '2' },
    { label: 'Wednesday', value: '3' },
    { label: 'Thursday', value: '4' },
    { label: 'Friday', value: '5' },
    { label: 'Saturday', value: '6' },
  ];

  user: AuthUser | null = null;
  studentRoutines = signal<{ [key: number]: any[]; }>([]);

  selectedRoutine = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;


  constructor(
    private toastService: ToastrService,
    private readonly apiSrv: ApiService,
    private readonly authService: AuthService,
    @Inject(PLATFORM_ID) private platformId: Object,
    private readonly utilSrv: UtilService
  ) {
    this.user = this.authService.getAuthSession().user;
  }

  private loadResources = (skip?: boolean) => {
    if(isPlatformBrowser(this.platformId)) {
      this.isLoading.set(true);

       this.apiSrv.get<any[]>(`/backend/student/timetable/${this.user?.id}`)
        .subscribe({
          next: (data) => {
            this.studentRoutines.set(this.utilSrv.groupRoutineToEachDay(data));

            //   [{ day: 1, routines: [] }]
          },
          error: (error) => {
            console.error('Error fetching data:', error);
            this.isLoading.set(false);

            // Handle error appropriately
          },
          complete: () => {
            this.isLoading.set(false);
          }
        });
    }

  }

  ngOnInit(): void {
    this.loadResources(true);
  }




  clickedHandler() {
    this.toastService.info('Clicked Class routine');
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedRoutine.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  handleCloseOffset() {
    this.selectedRoutine.set(null);
    this.anchorSelector.set('');
  }
}
