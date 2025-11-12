import {Component, computed, OnInit, signal, ViewChild} from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {SchoolClassForm} from '../../../../../components/forms/school-class-form/school-class-form';
import {ApiService} from '../../../../../common/service/api.service';
import {Loader} from '../../../../../common/loader/loader';
import {ToastrService} from 'ngx-toastr';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {EnrollmentForm} from '../../../../../components/forms/enrollment-form/enrollment-form';

@Component({
  selector: 'app-school-classes',
  imports: [
    DataTable,
    LearnoButton,
    PageHeader,
    TableSearch,
    LearnoModal,
    SchoolClassForm,
    SkeletonLoader,
    LearnoOffset,
  ],
  templateUrl: './school-classes.html',
  styleUrl: './school-classes.css'
})
export class SchoolClasses implements OnInit{

  isLoading = signal(false);
  sclasses = signal<any[]>([]);
  searchTerm = signal<string>('');


  selectedClass = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  filterClasses = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();


    if (!term) return this.sclasses();
    return this.sclasses().filter((s: any) => {
      const name = (s.name || '').toString().toLowerCase();
      const level = (s.grade_level || '').toString().toLowerCase();

      return name.includes(term) || level.includes(term);
    });
  });


  constructor(
    private readonly apiService: ApiService,
    private readonly toastService: ToastrService
  ) { }

  ngOnInit(): void {
        this.isLoading.set(true);
        this.apiService.get("/backend/school/classes")
          .subscribe({
            next: (data) => {
              this.sclasses.set(data);
            },
            error: (error) => {
              console.log(error);
              this.toastService.error("Error fetching school classes", "Error");
            },
            complete: () => {
              // console.log("complete");
              this.isLoading.set(false);
            }
          })
    }

  handleCloseOffset() {
    this.selectedClass.set(null);
  }



  deleteEnrollment() {

  }

  onPreview(evt: { anchorSelector: string; row: any }) {
    this.selectedClass.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  handleSuccessSubmit($event: { success: boolean }) {
    if($event.success) {
      this.isLoading.set(true);

      this.apiService.get("/backend/school/classes")
        .subscribe({
          next: (data) => {
            this.offsetCmp.close();
            this.sclasses.set(data);
          },
          error: (error) => {
            this.toastService.error("Error fetching Classes", "Error");
            this.isLoading.set(false);
          },
          complete: () => {
            this.isLoading.set(false);
          }
        })
    }
  }

  deleteClass() {
    const sel = this.selectedClass();
    if (!sel || !sel.id) {
      this.toastService.error('No Class selected');
      return;
    }
    const confirmed = window.confirm('Are you sure you want to delete this class? This action cannot be undone.');
    if (!confirmed) return;

    this.isLoading.set(true);
    this.apiService.delete(`/backend/school/classes?id=${this.selectedClass()['id']}`)
      .subscribe({
        next: () => {
          this.toastService.success('Class deleted successfully');
          // Update local state by filtering out the deleted enrollment
          this.sclasses.set(
            this.sclasses().filter(e => e.id !== this.selectedClass()['id'])
          );
          this.selectedClass.set(null);
          this.offsetCmp?.close();
          this.isLoading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastService.error('Failed to delete enrollment');
          this.isLoading.set(false);
        }
      });
  }

  onSearch(term: string) {
    this.searchTerm.set(term || '');
  }
}
