import {Component, Inject, PLATFORM_ID, signal, ViewChild} from '@angular/core';
import {DashboardCard} from "../../../../../common/dashboard-card/dashboard-card";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {RoutineCard} from "../../../../../components/routine-card/routine-card";
import {RoutineDayCard} from "../../../../../components/routine-day-card/routine-day-card";
import {SkeletonLoader} from "../../../../../common/skeleton-loader/skeleton-loader";
import {AuthUser} from '../../../../../common/auth/auth.models';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {UtilService} from '../../../../../common/service/util.service';
import {isPlatformBrowser} from '@angular/common';
import { LearnoButton } from "../../../../../common/learno-button/learno-button";
import { LearnoModal } from "../../../../../components/learno-modal/learno-modal";
import { VirtualClassForm } from "../../../../../components/forms/virtual-class-form/virtual-class-form";

@Component({
  selector: 'app-teacher-class-routine',
    imports: [
    DashboardCard,
    PageHeader,
    RoutineCard,
    RoutineDayCard,
    SkeletonLoader,
    LearnoButton,
    LearnoOffset,
    LearnoModal,
    VirtualClassForm
],
  templateUrl: './teacher-class-routine.html',
  styleUrl: './teacher-class-routine.css'
})
export class TeacherClassRoutine {
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
  teacherRoutines = signal<{ [key: number]: any[]; }>([]);
  classes = signal<any[]>([]);
  subjects = signal<any[]>([]);
  teachers = signal<any[]>([]);
  virtualClasses = signal<any[]>([]);
  virtualClassFormSelectedRoutine = signal<any[]>([]);

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
      const teacherId = this.user?.id;
      const routines$ = this.apiSrv.get<any[]>(`/backend/teacher/timetable/${teacherId}`);
      const classes$ = this.apiSrv.get<any[]>(`/backend/teacher/classes/${teacherId}`);
      const virtualClasses$ = this.apiSrv.get<any[]>(`/backend/virtual-class/teacher`);

      routines$.subscribe({
        next: (data) => {
          this.teacherRoutines.set(this.utilSrv.groupRoutineToEachDay(data));
          // Subjects assigned to this teacher derived from timetable
          const subjMap = new Map<string, { value: string; label: string }>();
          (data || []).forEach((r: any) => {
            const sid = String(r.subject_id || r.subjectId || '');
            const sname = String(r.subject_name || r.subjectName || '').trim();
            if (sid && sname && !subjMap.has(sid)) {
              subjMap.set(sid, { value: sid, label: sname });
            }
          });
          this.subjects.set(Array.from(subjMap.values()));

          this.isLoading.set(false);
        },
        error: (error) => {
          console.error('Error fetching data:', error);
          this.toastService.error('Error fetching teacher routine');
          this.isLoading.set(false);
        },
        complete: () => {
          this.isLoading.set(false);
        }
      });

      classes$.subscribe({
        next: (cls) => {
          this.classes.set(this.utilSrv.configureForOption(cls));
        },
        error: (err) => {
          console.error('Error fetching classes:', err);
          this.toastService.error('Error fetching teacher classes');
        }
      });

      virtualClasses$.subscribe({
        next: (vcls) => {
          this.virtualClasses.set(vcls);
          console.log('Virtual Classes:', vcls);
        },
        error: (err) => {
          console.error('Error fetching virtual classes:', err);
          this.toastService.error('Error fetching virtual classes');
        }
      });

      // Current teacher option and lock
      const teacherName = this.utilSrv.getTeacherFullname(this.user as AuthUser);
      this.teachers.set([{ value: String(teacherId || ''), label: teacherName }]);
    }

  }

  ngOnInit(): void {
    this.loadResources(true);
  }




  clickedHandler() {
    this.toastService.info('Clicked Class routine');
  }


  hasVirtual(period: any): boolean {
    const vlist = this.virtualClasses() || [];

    console.log(vlist, period)
    const pidSubject = String(period?.subject_id || period?.subjectId || '');
    const pidTeacher = String(period?.teacher_id || period?.teacherId || '');
    const pidClass = String(period?.class_id || period?.classId || '');

    return vlist.some((v: any) => {
      const vc = String(v?.class_id || v?.classId || '');
      const vs = String(v?.subject_id || v?.subjectId || '');
      const vt = String(v?.teacher_id || v?.teacherId || '');

      return vc === pidClass && vs === pidSubject && vt === pidTeacher;
    });
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedRoutine.set(evt.row);
    this.virtualClassFormSelectedRoutine
    .set(this.virtualClasses()
    .filter((vcls) => (vcls.class_id === evt.row.class_id && vcls.subject_id === evt.row.subject_id
    && vcls.teacher_id === evt.row.teacher_id)
  ));
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  handleCloseOffset() {
    this.selectedRoutine.set(null);
    this.virtualClassFormSelectedRoutine.set([]);
    this.anchorSelector.set('');
  }

    handleSuccessSubmit($event: { success: boolean }) {
    if($event.success) {
      this.offsetCmp?.close();
      this.loadResources()
    }
  }
}
