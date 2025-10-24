import {Component, OnInit, signal, computed, ViewChild} from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {Router} from "@angular/router";
import {ApiService} from '../../../../../common/service/api.service';
import {Loader} from '../../../../../common/loader/loader';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {EditStudentForm} from '../../../../../components/forms/edit-student-form/edit-student-form';
import {ToastrService} from 'ngx-toastr';

@Component({
  selector: 'app-students',
  imports: [
    PageHeader,
    TableSearch,
    DataTable,
    DataTableNumbering,
    LearnoButton,
    Loader,
    SkeletonLoader,
    LearnoOffset,
    LearnoModal,
    EditStudentForm
  ],
  templateUrl: './students.html',
  styleUrl: './students.css'
})
export class Students implements OnInit {

  isLoading = signal(false);
  students = signal<any[]>([]);
  searchTerm = signal<string>('');
  filteredStudents = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    if (!term) return this.students();
    return this.students().filter((s: any) => {
      const email = (s.email || '').toString().toLowerCase();
      const first = (s.first_name || '').toString().toLowerCase();
      const last = (s.last_name || '').toString().toLowerCase();
      const phone = (s.phone || '').toString().toLowerCase();
      return email.includes(term) || first.includes(term) || last.includes(term) || phone.includes(term);
    });
  });

  selectedEnrollment = signal<any | null>(null);
  anchorSelector = signal<string>('');
  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  constructor(
    private router: Router,
    private readonly apiSrv: ApiService,
    private readonly toastService: ToastrService,
    ) { }

  ngOnInit(): void {
    this.isLoading.set(true);
    this.apiSrv.get("/backend/school/students")
      .subscribe({
        next: (data) => {
          this.students.set(data);
          console.log(data, "this is the return data");
        },
        error: (error) => {
          console.log(error);
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

    async clickedHandler() {
        await this.router.navigate(['/admin/students/new']);
    }

  editEnrollment() {

  }

  deleteEnrollment() {
    const sel = this.selectedEnrollment();
    if (!sel || !sel.id) {
      this.toastService.error('No student selected');
      return;
    }

    this.isLoading.set(true);
    this.apiSrv.delete('/backend/school/students', { body: { id: sel.id }})
      .subscribe({
        next: () => {
          this.toastService.success('Student deleted successfully');
          this.apiSrv.get('/backend/school/students')
            .subscribe({
              next: (data) => {
                this.students.set(data);
                this.selectedEnrollment.set(null);
                this.offsetCmp?.close();
              },
              error: () => {
                this.toastService.error('Failed to refresh students');
              },
              complete: () => {
                this.isLoading.set(false);
              }
            });
        },
        error: (err) => {
          console.error(err);
          this.toastService.error('Failed to delete student');
          this.isLoading.set(false);
        }
      });
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedEnrollment.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  handleCloseOffset() {
    this.selectedEnrollment.set(null);
    this.anchorSelector.set('');
  }
}
