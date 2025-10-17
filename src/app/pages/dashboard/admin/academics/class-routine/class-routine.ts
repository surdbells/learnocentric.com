import {Component, Inject, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {ToastrService} from "ngx-toastr";
import {RoutineCard} from "../../../../../components/routine-card/routine-card";
import {RoutineDayCard} from "../../../../../components/routine-day-card/routine-day-card";
import {LearnoModal} from "../../../../../components/learno-modal/learno-modal";
import {RoutineForm} from "../../../../../components/forms/routine-form/routine-form";
import {FormControl, FormGroup, Validators} from "@angular/forms";
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {ActivatedRoute} from '@angular/router';
import {forkJoin} from 'rxjs';
import {ApiService} from '../../../../../common/service/api.service';
import {Loader} from '../../../../../common/loader/loader';
import {AuthUser} from '../../../../../common/auth/auth.models';
import {AuthService} from '../../../../../common/auth/auth.service';
import {isPlatformBrowser} from '@angular/common';
import {UtilService} from '../../../../../common/service/util.service';

@Component({
  selector: 'app-class-routine',
  imports: [
    PageHeader,
    RoutineCard,
    RoutineDayCard,
    LearnoModal,
    RoutineForm,
    DashboardCard,
    Loader
  ],
  templateUrl: './class-routine.html',
  styleUrl: './class-routine.css'
})
export class ClassRoutine implements OnInit{

  isLoading = signal(false);
  days = [
    { label: 'Monday', value: '1' },
    { label: 'Tuesday', value: '2' },
    { label: 'Wednesday', value: '3' },
    { label: 'Thursday', value: '4' },
    { label: 'Friday', value: '5' },
    { label: 'Saturday', value: '6' },
  ];
  userRole: string;
  user: AuthUser | null = null;
  subjects = signal<any[]>([]);
  classes = signal<any[]>([]);
  teachers = signal<any[]>([]);
  teacherRoutines = signal<any[]>([]);


  constructor(
    private toastService: ToastrService,
    private route: ActivatedRoute,
    private readonly apiSrv: ApiService,
    private readonly authService: AuthService,
    @Inject(PLATFORM_ID) private platformId: Object,
    private readonly utilSrv: UtilService
  ) {
    this.userRole = this.route.snapshot.data['user'];
    this.user = this.authService.getAuthSession().user;
  }


  ngOnInit(): void {
    if(isPlatformBrowser(this.platformId)) {
      this.isLoading.set(true);
      const schoolData$ = forkJoin({
        classes: this.apiSrv.get<any[]>('/backend/school/classes'),
        subjects: this.apiSrv.get<any[]>('/backend/school/subjects'),
        teachers: this.apiSrv.get<any[]>('/backend/school/teachers'),
        teacherRoutines: this.apiSrv.get<any[]>(`/backend/${this.user?.role}/timetable/${this.user?.id}`),
      });

      schoolData$
        //   .pipe(
        //   takeUntil(this.destroy$)
        // )
        .subscribe({
          next: (data) => {
            this.classes.set(this.utilSrv.configureForOption(data.classes));
            this.subjects.set(this.utilSrv.configureForOption(data.subjects));
            this.teachers.set(this.utilSrv.configureForOption(data.teachers));
            this.teacherRoutines.set(this.utilSrv.groupRoutineToEachDay(data.teacherRoutines));

          //   [{ day: 1, routines: [] }]
          },
          error: (error) => {
            console.error('Error fetching data:', error);
            this.isLoading.set(false);

            // Handle error appropriately
          },
          complete: () => {
            this.isLoading.set(false);
            // console.log(this.groupRoutineToEachDay());
          }
        });
    }
  }




  clickedHandler() {
        this.toastService.info('Clicked Class routine');
    }




}
