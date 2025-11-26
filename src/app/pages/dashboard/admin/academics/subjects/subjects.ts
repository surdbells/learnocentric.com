import {Component, computed, OnInit, signal, ViewChild} from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {SubjectForm} from '../../../../../components/forms/subject-form/subject-form';
import {ApiService} from '../../../../../common/service/api.service';
import {Loader} from '../../../../../common/loader/loader';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {ToastrService} from 'ngx-toastr';

@Component({
  selector: 'app-subjects',
  imports: [
    DataTable,
    PageHeader,
    TableSearch,
    LearnoModal,
    SubjectForm,
    Loader,
    DataTableNumbering,
    SkeletonLoader,
    LearnoButton,
    LearnoOffset
  ],
  templateUrl: './subjects.html',
  styleUrl: './subjects.css'
})
export class Subjects implements OnInit{
  isLoading = signal(false);
  subjects = signal<any[]>([]);

  searchTerm = signal<string>('');

  selectSubject = signal<any | null>(null);
  anchorSelector = signal<string>('');

   currentPage = signal<number>(1);

  onPageChange(p: number) {
    this.currentPage.set(Math.max(1, Number(p || 1)));
  }

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  filterSubjects = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();

    if (!term) return this.subjects();
    return this.subjects().filter((s: any) => {
      const name = (s.name || '').toString().toLowerCase();
      const code = (s.code || '').toString().toLowerCase();
      const description = (s.description || '').toString().toLowerCase();

      return name.includes(term) || code.includes(term) || description.includes(term);
    });
  });

  constructor(
    private readonly apiService: ApiService,
    private readonly toastService: ToastrService,
  ) {}

  ngOnInit(): void {

    this.isLoading.set(true);

    this.apiService.get('/backend/school/subjects')
      .subscribe({
        next: (data) => {
          this.subjects.set(data);
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

  searchSubjects($event: string) {
    this.searchTerm.set($event || '');
  }

  handleSuccessSubmit($event: { success: boolean }) {
    if($event.success) {
      this.offsetCmp?.close();
      this.isLoading.set(true);

      this.apiService.get("/backend/school/subjects")
        .subscribe({
          next: (data) => {
            this.subjects.set(data);
          },
          error: (error) => {
            this.toastService.error("Error fetching subjects", "Error");
            this.isLoading.set(false);
          },
          complete: () => {
            this.isLoading.set(false);
          }
        })
    }
  }

  handleCloseOffset() {
    this.selectSubject.set(null);
  }

  onPreview(evt: { anchorSelector: string; row: any }) {
    this.selectSubject.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  deleteSubject() {
    const sel = this.selectSubject();
    if (!sel || !sel.id) {
      this.toastService.error('No subject selected');
      return;
    }

    this.isLoading.set(true);
    this.apiService.delete(`/backend/school/subjects?id=${this.selectSubject()['id']}`)
      .subscribe({
        next: () => {
          this.toastService.success('Subject deleted successfully');

          this.subjects.set(
            this.subjects().filter(e => e.id !== this.selectSubject()['id'])
          );
          this.selectSubject.set(null);
          this.offsetCmp?.close();
          this.isLoading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastService.error('Failed to delete subject');
          this.isLoading.set(false);
        }
      });
  }

}
