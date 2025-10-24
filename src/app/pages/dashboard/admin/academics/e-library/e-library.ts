import {Component, Inject, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {TableSearch} from '../../../../../components/table-search/table-search';
import {DataTable} from '../../../../../components/data-table/data-table';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {ELibraryForm} from '../../../../../components/forms/e-library-form/e-library-form';
import {ActivatedRoute} from '@angular/router';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {isPlatformBrowser} from '@angular/common';
import {forkJoin} from 'rxjs';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {Loader} from '../../../../../common/loader/loader';
import {UtilService} from '../../../../../common/service/util.service';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';

@Component({
  selector: 'app-e-library',
  imports: [
    PageHeader,
    LearnoButton,
    TableSearch,
    DataTable,
    LearnoModal,
    RoutineForm,
    ELibraryForm,
    DataTableNumbering,
    Loader,
    SkeletonLoader
  ],
  templateUrl: './e-library.html',
  styleUrl: './e-library.css'
})
export class ELibrary implements OnInit{

  userRole: string;
  isLoading = signal(false);
  classes = signal<any[]>([]);
  subjects = signal<any[]>([]);
  books = signal<any[]>([]);

  selectedElibrary = signal<any | null>(null);
  anchorSelector = signal<string>('');

  constructor(
    private route: ActivatedRoute,
    private readonly apiService: ApiService,
    private readonly toastService: ToastrService,
    @Inject(PLATFORM_ID) private platformId: Object,
    private readonly utilSrv: UtilService
  ) {
    this.userRole = this.route.snapshot.data['user'];
  }

  ngOnInit(): void {
    this.isLoading.set(true);

    if(isPlatformBrowser(this.platformId)) {

      const resource$ = forkJoin({
        classes: this.apiService.get('/backend/school/classes'),
        subjects: this.apiService.get('/backend/school/subjects'),
        books: this.apiService.get('/backend/storage/resources')
      })

      resource$.subscribe({
        next: (data) => {
          this.books.set(data.books);
          this.classes.set(this.utilSrv.configureForOption(data.classes));
          this.subjects.set(this.utilSrv.configureForOption(data.subjects));
        },
        error: (error) => {
          this.toastService.error("Error fetching resources", "Error");
          console.log(error);
        },
        complete: () => {
          console.log("complete");
          this.isLoading.set(false);
        }
      })

    }
  }

  clickhandker() {

  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedElibrary.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }
}
