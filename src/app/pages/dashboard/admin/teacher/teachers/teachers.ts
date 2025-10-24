import {Component, computed, OnInit, signal, ViewChild} from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {Router} from "@angular/router";
import {ApiService} from '../../../../../common/service/api.service';
import {Loader} from '../../../../../common/loader/loader';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {ToastrService} from 'ngx-toastr';
import {EditStudentForm} from '../../../../../components/forms/edit-student-form/edit-student-form';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';

@Component({
  selector: 'app-teachers',
  imports: [
    DataTable,
    DataTableNumbering,
    PageHeader,
    TableSearch,
    LearnoButton,
    Loader,
    SkeletonLoader,
    LearnoOffset,
    EditStudentForm,
    LearnoModal
  ],
  templateUrl: './teachers.html',
  styleUrl: './teachers.css'
})
export class Teachers implements OnInit {

  isLoading = signal(false);
  teachers = signal<any[]>([]);

  selectedEnrollment = signal<any | null>(null);
  anchorSelector = signal<string>('');
  searchTerm = signal<string>('');
  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  filteredTeachers = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    if (!term) return this.teachers();
    return this.teachers().filter((s: any) => {
      const email = (s.email || '').toString().toLowerCase();
      const first = (s.first_name || '').toString().toLowerCase();
      const last = (s.last_name || '').toString().toLowerCase();
      const phone = (s.phone || '').toString().toLowerCase();
      return email.includes(term) || first.includes(term) || last.includes(term) || phone.includes(term);
    });
  });
  constructor(
    private router: Router,
    private readonly apiSrv: ApiService,
    private readonly toastService: ToastrService,
  ) { }


  ngOnInit(): void {
    this.isLoading.set(true);
    this.apiSrv.get("/backend/school/teachers")
      .subscribe({
        next: (data) => {
          this.teachers.set(data);
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

    async clickedHandler() {
        console.log('clicked');
        await this.router.navigate(['/admin/teachers/new']);
    }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedEnrollment.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  onSearch(term: string) {
    this.searchTerm.set(term || '');
  }

  editEnrollment() {

  }

  deleteEnrollment() {
      const sel = this.selectedEnrollment();
      if (!sel || !sel.id) {
        this.toastService.error('No teacher selected');
        return;
      }

      this.isLoading.set(true);
      this.apiSrv.delete('/backend/school/teachers', { body: { id: sel.id }})
        .subscribe({
          next: () => {
            this.toastService.success('Teacher deleted successfully');
            this.apiSrv.get('/backend/school/teachers')
              .subscribe({
                next: (data) => {
                  this.teachers.set(data);
                  this.selectedEnrollment.set(null);
                  this.offsetCmp?.close();
                },
                error: () => {
                  this.toastService.error('Failed to refresh teachers');
                },
                complete: () => {
                  this.isLoading.set(false);
                }
              });
          },
          error: (err) => {
            console.error(err);
            this.toastService.error('Failed to delete teacher');
            this.isLoading.set(false);
          }
        });
  }

  handleCloseOffset() {
    this.selectedEnrollment.set(null);
    this.anchorSelector.set('');
  }
}
