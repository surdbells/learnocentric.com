import {Component, computed, Inject, OnInit, PLATFORM_ID, signal, ViewChild} from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {ToastrService} from "ngx-toastr";
import {RoutineCard} from "../../../../../components/routine-card/routine-card";
import {RoutineDayCard} from "../../../../../components/routine-day-card/routine-day-card";
import {LearnoModal} from "../../../../../components/learno-modal/learno-modal";
import {RoutineForm} from "../../../../../components/forms/routine-form/routine-form";
import {FormControl, FormGroup, FormsModule, Validators} from "@angular/forms";
import {DashboardCard} from '../../../../../common/dashboard-card/dashboard-card';
import {ActivatedRoute} from '@angular/router';
import {catchError, forkJoin, of} from 'rxjs';
import {ApiService} from '../../../../../common/service/api.service';
import {Loader} from '../../../../../common/loader/loader';
import {AuthUser} from '../../../../../common/auth/auth.models';
import {AuthService} from '../../../../../common/auth/auth.service';
import {isPlatformBrowser} from '@angular/common';
import {UtilService} from '../../../../../common/service/util.service';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {DataTable} from '../../../../../components/data-table/data-table';

@Component({
  selector: 'app-class-routine',
  imports: [
    PageHeader,
    RoutineCard,
    RoutineDayCard,
    LearnoModal,
    RoutineForm,
    DashboardCard,
    SkeletonLoader,
    FormsModule,
    LearnoOffset,
    LearnoButton
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
  teacherRoutines = signal<{ [key: number]: any[]; }>([]);
  filterWithClass = signal<string>('');

  selectedRoutine = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  // filterEnrollments = computed(() => {
  //   if(this.filterWithClass()) {
  //     return this.results().filter((e) => e.class_id == this.filterWithClass());
  //   }
  //
  //   // if (!term) return this.results();
  //   return this.rou().filter((s: any) => {
  //     const sname = (s.subject_name || '').toString().toLowerCase();
  //     const first = (s.first_name || '').toString().toLowerCase();
  //     const last = (s.last_name || '').toString().toLowerCase();
  //     const code = (s.subject_code || '').toString().toLowerCase();
  //     return sname.includes(term) || first.includes(term) || last.includes(term) || code.includes(term);
  //   }
  //   );
  // });
  selectedClass: any = 1;

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

  private loadResources = (skip?: boolean) => {
    if(isPlatformBrowser(this.platformId)) {
      this.isLoading.set(true);
      const schoolData$ = forkJoin({
        classes: this.apiSrv.get<any[]>('/backend/school/classes')
          .pipe(catchError((err) => { this.toastService.error("Error fetching school classes", "Error"); return of([] as any[]); })),
        subjects: this.apiSrv.get<any[]>('/backend/school/subjects')
          .pipe(catchError((err) => { this.toastService.error("Error fetching school subjects", "Error"); return of([] as any[]); })),
        teachers: this.apiSrv.get<any[]>('/backend/school/teachers')
          .pipe(catchError((err) => { this.toastService.error("Error fetching school teachers", "Error"); return of([] as any[]); })),
        userRoutine: this.apiSrv.get<any[]>(`${this.user?.role == 'school_admin' ? `/backend/timetable/periods?classId=${this.selectedClass}` :
          `/backend/${this.user?.role}/timetable/${this.user?.id}`} `)
          .pipe(catchError((err) => { this.toastService.error("Error fetching school tim", "Error"); return of([] as any[]); })),
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
            this.teacherRoutines.set(this.utilSrv.groupRoutineToEachDay(data.userRoutine));

            console.log(this.utilSrv.groupRoutineToEachDay(data.userRoutine), "Here we have it")

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

  ngOnInit(): void {
    this.loadResources(true);
  }




  clickedHandler() {
        this.toastService.info('Clicked Class routine');
    }

  onSelectClass() {
    this.isLoading.set(true);
    this.apiSrv.get(`/backend/timetable/periods?classId=${this.selectedClass}`)
      .subscribe({
        next: (data) => {
          this.teacherRoutines.set(this.utilSrv.groupRoutineToEachDay(data));
        },
        error: (error) => {
          this.toastService.error("Error fetching school tim", "Error");
          this.isLoading.set(false);
        },
        complete: () => {
          this.isLoading.set(false);
        }
      })
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedRoutine.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  deleteEnrollment() {
    const sel = this.selectedRoutine();
    if (!sel || !sel.id) {
      this.toastService.error('No Routine selected');
      return;
    }

    this.isLoading.set(true);
    this.apiSrv.delete(`/backend/timetable/periods??id=${this.selectedRoutine()['id']}`)
      .subscribe({
        next: () => {
          this.toastService.success('Routine deleted successfully');
          // Update local state by filtering out the deleted enrollment

          // this.teacherRoutines.set(this.utilSrv.groupRoutineToEachDay());
          this.selectedRoutine.set(null);
          this.offsetCmp?.close();
          this.isLoading.set(false);

          this.loadResources();
        },
        error: (err) => {
          console.error(err);
          this.toastService.error('Failed to delete enrollment');
          this.isLoading.set(false);
        }
      });
  }



  handleCloseOffset() {
    this.selectedRoutine.set(null);
    this.anchorSelector.set('');
  }
}
