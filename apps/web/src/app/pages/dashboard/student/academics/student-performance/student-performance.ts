import {Component, computed, signal, ViewChild} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {DataTable} from '../../../../../components/data-table/data-table';
import {TableSearch} from '../../../../../components/table-search/table-search';
import {DatePipe} from '@angular/common';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {Router} from '@angular/router';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {UtilService} from '../../../../../common/service/util.service';
import {catchError, forkJoin, of} from 'rxjs';
import {IEnrollmentStoreProp} from '../../../admin/student/enrollment/enrollment';
import {AuthService} from '../../../../../common/auth/auth.service';

@Component({
  selector: 'app-student-performance',
  imports: [
    PageHeader,
    DataTable,
    TableSearch,
    DatePipe,
    LearnoButton,
    LearnoOffset,
    SkeletonLoader,
    DataTableNumbering
  ],
  templateUrl: './student-performance.html',
  styleUrl: './student-performance.css'
})
export class StudentPerformance {

  isLoading = signal(false);
  results = signal<any[]>([]);
  searchTerm = signal<string>('');

  selectedResult = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;

  filterPerformance = computed(() => {
    const term = this.searchTerm().toLowerCase().trim();
    if (!term) return this.results();
    return this.results().filter((s: any) => {
      const sname = (s.subject_name || '').toString().toLowerCase();
      const mark = (s.marks_obtained || '').toString().toLowerCase();
      const code = (s.subject_code || '').toString().toLowerCase();
      return sname.includes(term) || mark.includes(term) ||  code.includes(term);
    });
  });

  constructor(
    private router: Router,
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService,
    private readonly auth: AuthService
  ) { }

  ngOnInit(): void {
    this.isLoading.set(true);
      const user = this.auth.getAuthSession().user;
      this.apiSrv.get(`/backend/student/grades/${user?.id}`)
      .subscribe({
        next: (data) => {
          this.results.set(data);
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

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedResult.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  handleCloseOffset() {
    this.selectedResult.set(null);
  }
}
