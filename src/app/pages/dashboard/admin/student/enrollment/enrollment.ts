import {Component, computed, ElementRef, OnInit, signal, ViewChild} from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {SkeletonLoader} from "../../../../../common/skeleton-loader/skeleton-loader";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {Router} from '@angular/router';
import {ApiService} from '../../../../../common/service/api.service';
import {forkJoin} from 'rxjs';
import {ToastrService} from 'ngx-toastr';
import {UtilService} from '../../../../../common/service/util.service';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {EnrollmentForm} from '../../../../../components/forms/enrollment-form/enrollment-form';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {CurrencyPipe, NgIf} from '@angular/common';


export interface IEnrollmentStoreProp {
  id: number;
  student_id: number;
  class_id: number;
  grade_level: number;
  email: string;
  first_name: string;
  last_name: string;
  phone: string;
}

@Component({
  selector: 'app-enrollment',
  imports: [
    DataTable,
    DataTableNumbering,
    LearnoButton,
    PageHeader,
    SkeletonLoader,
    TableSearch,
    LearnoOffset,
    EnrollmentForm,
    LearnoModal,
    RoutineForm,
    CurrencyPipe,
    NgIf
  ],
  templateUrl: './enrollment.html',
  styleUrl: './enrollment.css'
})
export class Enrollment implements OnInit {

  isLoading = signal(false);
  enrollments = signal<any[]>([]);
  classes = signal<any[]>([]);
  students = signal<any[]>([]);
  searchTerm = signal<string>('');
  filterWithClass = signal<string>('');

  selectedEnrollment = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;
  currentPage = signal<number>(1);

  onPageChange(p: number) {
    this.currentPage.set(Math.max(1, Number(p || 1)));
  }

  filterEnrollments = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    if(this.filterWithClass()) {
      return this.enrollments().filter((e) => e.class_id == this.filterWithClass());
    }

    if (!term) return this.enrollments();
    return this.enrollments().filter((s: any) => {
      const email = (s.email || '').toString().toLowerCase();
      const first = (s.first_name || '').toString().toLowerCase();
      const last = (s.last_name || '').toString().toLowerCase();
      const phone = (s.phone || '').toString().toLowerCase();
      const gLevel = (s.grade_level || 1).toString().toLowerCase();
      return email.includes(term) || first.includes(term) || last.includes(term) || phone.includes(term) || gLevel.includes(term);
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
      classes: this.apiSrv.get("/backend/school/classes"),
      enrollments: this.apiSrv.get("/backend/school/enrollments"),
      students: this.apiSrv.get("/backend/school/students")
    })
      .subscribe({
        next: (data) => {
          this.enrollments.set(data.enrollments);
          this.classes.set(this.utilSrv.configureForOption(data.classes));
          this.students.set(this.utilSrv.configureForOption(data.students));
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

  onFilter($event: string) {
    console.log($event, "listener");
    this.filterWithClass.set($event);
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedEnrollment.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  editEnrollment() {

  }

  deleteEnrollment() {
    const sel = this.selectedEnrollment();
    if (!sel || !sel.id) {
      this.toastSrv.error('No enrollment selected');
      return;
    }

    this.isLoading.set(true);
    this.apiSrv.delete(`/backend/school/enrollments?id=${this.selectedEnrollment()['id']}`)
      .subscribe({
        next: () => {
          this.toastSrv.success('Enrollment deleted successfully');
          // Update local state by filtering out the deleted enrollment
          this.enrollments.set(
            this.enrollments().filter(e => e.id !== this.selectedEnrollment()['id'])
          );
          this.selectedEnrollment.set(null);
          this.offsetCmp?.close();
          this.isLoading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastSrv.error('Failed to delete enrollment');
          this.isLoading.set(false);
        }
      });


    // this.apiSrv.delete(`/backend/school/enrollments?id=${this.selectedEnrollment()['id']}`)
    //   .subscribe({
    //     next: () => {
    //       this.toastSrv.success('Enrollment deleted successfully');
    //       // Refresh enrollments list
    //
    //     },
    //     error: (err) => {
    //       console.error(err);
    //       this.toastSrv.error('Failed to delete enrollment');
    //       this.isLoading.set(false);
    //     }
    //   });
  }

  onSubmitted(enrollment: IEnrollmentStoreProp) {
    this.enrollments.set([...this.enrollments(), {}]);
  }

  handleSuccessSubmit($event: { success: boolean }) {
    if($event.success) {
      this.isLoading.set(true);
      this.offsetCmp?.close();

      this.apiSrv.get("/backend/school/enrollments")
        .subscribe({
          next: (data) => {
            this.enrollments.set(data);
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
    this.selectedEnrollment.set(null);
  }
}
