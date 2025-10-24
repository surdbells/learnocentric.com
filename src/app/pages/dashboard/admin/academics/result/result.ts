import {Component, computed, signal, ViewChild} from '@angular/core';
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
import {IEnrollmentStoreProp} from '../../student/enrollment/enrollment';
import {DatePipe} from '@angular/common';
import {EnrollmentForm} from '../../../../../components/forms/enrollment-form/enrollment-form';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {ResultForm} from '../../../../../components/forms/result-form/result-form';

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
    EnrollmentForm,
    SkeletonLoader,
    ResultForm
  ],
  templateUrl: './result.html',
  styleUrl: './result.css'
})
export class Result {
  isLoading = signal(false);
  results = signal<any[]>([]);
  classes = signal<any[]>([]);
  students = signal<any[]>([]);
  subjects = signal<any[]>([]);
  searchTerm = signal<string>('');
  filterWithClass = signal<string>('');
  filterWithStudent = signal<string>('');
  filterWithSubject = signal<string>('');

  selectedResult = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  filterEnrollments = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    if(this.filterWithClass()) {
      return this.results().filter((e) => e.class_id == this.filterWithClass());
    }
    if(this.filterWithStudent()) {
      return this.results().filter((e) => e.student_id == this.filterWithStudent());
    }
    if(this.filterWithSubject()) {
      return this.results().filter((e) => e.subject_id == this.filterWithSubject());
    }

    if (!term) return this.results();
    return this.results().filter((s: any) => {
      const sname = (s.subject_name || '').toString().toLowerCase();
      const first = (s.first_name || '').toString().toLowerCase();
      const last = (s.last_name || '').toString().toLowerCase();
      const code = (s.subject_code || '').toString().toLowerCase();
      return sname.includes(term) || first.includes(term) || last.includes(term) || code.includes(term);
    });
  });

  constructor(
    private router: Router,
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService
  ) { }

  ngOnInit(): void {
    this.isLoading.set(true);

    forkJoin({
      classes: this.apiSrv.get("/backend/school/classes")
        .pipe(catchError((err) => { this.toastSrv.error("Error fetching school classes", "Error"); return of([] as any[]); })),
      results: this.apiSrv.get("/backend/school/grades")
        .pipe(catchError((err) => { this.toastSrv.error("Error fetching school results", "Error"); return of([] as any[]); })),
      students: this.apiSrv.get("/backend/school/students")
        .pipe(catchError((err) => { this.toastSrv.error("Error fetching school students", "Error"); return of([] as any[]); })),
      subjects: this.apiSrv.get("/backend/school/subjects")
        .pipe(catchError((err) => { this.toastSrv.error("Error fetching school subjects", "Error"); return of([] as any[]); })),
    })
      .subscribe({
        next: (data) => {
          this.results.set(data.results);
          this.classes.set(this.utilSrv.configureForOption(data.classes));
          this.students.set(this.utilSrv.configureForOption(data.students));
          this.subjects.set(this.utilSrv.configureForOption(data.subjects));
        },
        error: (error) => {
          this.toastSrv.error("Error fetching enrollments", "Error");
          this.isLoading.set(false);
        },
        complete: () => {
          console.log("complete");
          this.isLoading.set(false);
        }
      })
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
    this.selectedResult.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  editEnrollment() {

  }

  deleteEnrollment() {
    const sel = this.selectedResult();
    if (!sel || !sel.id) {
      this.toastSrv.error('No enrollment selected');
      return;
    }
    const confirmed = window.confirm('Are you sure you want to delete this result? This action cannot be undone.');
    if (!confirmed) return;

    this.isLoading.set(true);
    this.apiSrv.delete(`/backend/school/grades?id=${this.selectedResult()['id']}`)
      .subscribe({
        next: () => {
          this.toastSrv.success('Enrollment deleted successfully');
          // Update local state by filtering out the deleted enrollment
          this.results.set(
            this.results().filter(e => e.id !== this.selectedResult()['id'])
          );
          this.selectedResult.set(null);
          this.offsetCmp?.close();
          this.isLoading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastSrv.error('Failed to delete enrollment');
          this.isLoading.set(false);
        }
      });
  }

  onSubmitted(results: IEnrollmentStoreProp) {
    this.results.set([...this.results(), {}]);
  }

  handleSuccessSubmit($event: { success: boolean }) {
    if($event.success) {
      this.isLoading.set(true);

      this.apiSrv.get("/backend/school/grades")
        .subscribe({
          next: (data) => {
            this.results.set(data);
          },
          error: (error) => {
            this.toastSrv.error("Error fetching enrollments", "Error");
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
  }
}
