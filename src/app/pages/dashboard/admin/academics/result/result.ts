import {Component, computed, Inject, PLATFORM_ID, signal, ViewChild} from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {DataTable} from "../../../../../components/data-table/data-table";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {Router} from '@angular/router';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {UtilService} from '../../../../../common/service/util.service';
import {catchError, forkJoin, of} from 'rxjs';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {ResultForm} from '../../../../../components/forms/result-form/result-form';
import {AuthService} from '../../../../../common/auth/auth.service';
import {AuthUser} from '../../../../../common/auth/auth.models';
import { IEnrollmentStoreProp } from '../../student/enrollment/enrollment';

@Component({
  selector: 'app-result',
  imports: [
    PageHeader,
    DataTable,
    TableSearch,
    LearnoModal,
    RoutineForm,
    DataTableNumbering,
    LearnoButton,
    LearnoOffset,
    DatePipe,
    ResultForm,
    SkeletonLoader,
    ResultForm
  ],
  templateUrl: './result.html',
  styleUrl: './result.css'
})
export class Result {
onClassChange($event: any) {
  //Todo filter students by class
  console.log($event.target.value, "event data");
  console.log(this.filterStudents(), "students data");
  this.filterStudents.set(this.students().filter((s: any) => String(s.class_id ?? s.classId ?? s.class)?.toString() === String($event.target.value)));
}
  isLoading = signal(false);
  results = signal<any[]>([]);
  classes = signal<any[]>([]);
  students = signal<any[]>([]);
  filterStudents = signal<any[]>([]); 
  subjects = signal<any[]>([]);
  searchTerm = signal<string>('');
  filterWithClass = signal<string>('');
  filterWithStudent = signal<string>('');
  filterWithSubject = signal<string>('');
  user= signal<AuthUser|null>(null);

  selectedResult = signal<any | null>(null);
  // Holds the selected student with aggregated results for preview
  selectedStudent = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;
  currentPage = signal<number>(1);

  onPageChange(p: number) {
    this.currentPage.set(Math.max(1, Number(p || 1)));
  }
 
  // Aggregated student rows with result count and nested results for preview
  studentRows = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    let rows = this.results();

    if (this.filterWithClass()) {
      rows = rows.filter((e) => e.class_id == this.filterWithClass());
    }
    if (this.filterWithStudent()) {
      rows = rows.filter((e) => e.student_id == this.filterWithStudent());
    }
    if (this.filterWithSubject()) {
      rows = rows.filter((e) => e.subject_id == this.filterWithSubject());
    }

    if (term) {
      rows = rows.filter((s: any) => {
        const first = (s.first_name || '').toString().toLowerCase();
        const last = (s.last_name || '').toString().toLowerCase();
        return first.includes(term) || last.includes(term);
      });
    }

    const map = new Map<string, any>();
    for (const r of rows) {
      const key = String(r.student_id);
      let agg = map.get(key);
      if (!agg) {
        agg = {
          id: r.student_id,
          student_id: r.student_id,
          first_name: r.first_name,
          last_name: r.last_name,
          class_name: r.class_name,
          result_count: 0,
          results: [] as any[]
        };
        map.set(key, agg);
      }
      agg.results.push(r);
      agg.result_count = agg.results.length;
    }

    return Array.from(map.values());
  });

  constructor(
    private router: Router,
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService,
    private authSrv: AuthService,
    @Inject(PLATFORM_ID) private platformId: object,
  ) {
    this.user.set(this.authSrv.getAuthSession().user)
  }

  ngOnInit(): void {
    if(isPlatformBrowser(this.platformId)) {
      this.isLoading.set(true);

      forkJoin({
        classes: this.apiSrv.get(this.user()?.role.includes('admin') ? "/backend/school/classes" : `/backend/teacher/classes/${this.user()?.id}`)
          .pipe(catchError((err) => { this.toastSrv.error("Error fetching school classes", "Error"); return of([] as any[]); })),
        results: this.apiSrv.get("/backend/school/grades")
          .pipe(catchError((err) => { this.toastSrv.error("Error fetching school results", "Error"); return of([] as any[]); })),
        students: this.apiSrv.get(this.user()?.role.includes('admin') ? "/backend/school/enrollments": `/backend/teacher/students/${this.user()?.id}`)
          .pipe(catchError((err) => { this.toastSrv.error("Error fetching school students", "Error"); return of([] as any[]); })),
        subjects: this.apiSrv.get("/backend/school/subjects")
          .pipe(catchError((err) => { this.toastSrv.error("Error fetching school subjects", "Error"); return of([] as any[]); })),
      })
        .subscribe({
          next: (data) => {
            this.results.set(data.results?.filter((res: any) => data.students.some((stu: any) => stu.student_id == res.student_id)));
            this.classes.set(this.utilSrv.configureForOption(data.classes));
            this.students.set(this.utilSrv.configureForOption(data.students, 'student_id'));
            this.filterStudents.set(this.utilSrv.configureForOption(data.students, 'student_id'));
            this.subjects.set(this.utilSrv.configureForOption(data.subjects));
          },
          error: (error) => {
            this.toastSrv.error("Error fetching Results", "Error");
            this.isLoading.set(false);
          },
          complete: () => {
            console.log("complete");
            this.isLoading.set(false);
          }
        })
    }
  }

  onSearch(term: string) {
    this.searchTerm.set(term || '');
  }

  onFilter($event: string, target: string) {
    console.log($event, "listener");
    if(target == "student") {
      this.filterWithStudent.set($event);
      return;
    }
    if(target == "subject") {
      this.filterWithSubject.set($event);
      return;
    }
    if(target == "class") {
      this.filterWithClass.set($event);
      return;
    }
    this.filterWithStudent.set('');
    this.filterWithSubject.set('');
    this.filterWithClass.set('');
    this.searchTerm.set($event || '');
    return;
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    // Preview a student aggregate row
    this.selectedStudent.set(evt.row);
    this.selectedResult.set(null);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  editResult(row: any) {
    // Open modal with the selected result row for editing
    this.selectedResult.set(row);
  }

  deleteResult(row: any) {
    const sel = row;
    if (!sel || !sel.id) {
      this.toastSrv.error('No Result selected');
      return;
    }
    const confirmed = window.confirm('Are you sure you want to delete this result? This action cannot be undone.');
    if (!confirmed) return;

    this.isLoading.set(true);
    this.apiSrv.delete(`/backend/school/grades?id=${sel.id}`)
      .subscribe({
        next: () => {
          this.toastSrv.success('Result deleted successfully');
          // Update local state by filtering out the deleted Result
          this.results.set(
            this.results().filter(e => e.id !== sel.id)
          );
          this.selectedResult.set(null);
          // If preview open, update selectedStudent's list or close
          const current = this.selectedStudent();
          if (current) {
            const updated = {
              ...current,
              results: (current.results || []).filter((r: any) => r.id !== sel.id),
              result_count: Math.max(0, (current.result_count || 0) - 1)
            };
            this.selectedStudent.set(updated);
          }
          this.offsetCmp?.close();
          this.isLoading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastSrv.error('Failed to delete Result');
          this.isLoading.set(false);
        }
      });
  }

  // onSubmitted(results: IEnrollmentStoreProp) {
  //   this.results.set([...this.results(), {}]);
  // }

  handleSuccessSubmit($event: { success: boolean }) {
    if($event.success) {
      this.isLoading.set(true);

      this.apiSrv.get("/backend/school/grades")
        .subscribe({
          next: (data) => {
            this.results.set(data);
          },
          error: (error) => {
            this.toastSrv.error("Error fetching Results", "Error");
            this.isLoading.set(false);
          },
          complete: () => {
            this.isLoading.set(false);
          }
        })
    }
  }

  handleCloseOffset() {
    this.selectedResult.set(null);
    this.selectedStudent.set(null);
  }
}
