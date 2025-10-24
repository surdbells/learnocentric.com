import {Component, Inject, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TableSearch} from '../../../../../components/table-search/table-search';
import {DataTable} from '../../../../../components/data-table/data-table';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {CurrencyPipe, isPlatformBrowser, CommonModule} from '@angular/common';
import {forkJoin, catchError, of} from 'rxjs';
import {FeesForm} from '../../../../../components/forms/fees-form/fees-form';
import {UtilService} from '../../../../../common/service/util.service';
import {Loader} from '../../../../../common/loader/loader';
import {AuthService} from '../../../../../common/auth/auth.service';
import {AuthUser} from '../../../../../common/auth/auth.models';
import {ActivatedRoute} from '@angular/router';
import {SkeletonLoader} from '../../../../../common/skeleton-loader/skeleton-loader';
import {LearnoOffset} from '../../../../../components/learno-offset/learno-offset';

@Component({
  selector: 'app-fees',
  imports: [
    CommonModule,
    PageHeader,
    TableSearch,
    DataTable,
    DataTableNumbering,
    LearnoModal,
    RoutineForm,
    FeesForm,
    Loader,
    SkeletonLoader,
    LearnoOffset,
    CurrencyPipe
  ],
  templateUrl: './fees.html',
  styleUrl: './fees.css'
})
export class Fees implements OnInit{

  isLoading = signal(false);
  fees = signal<any[]>([]);
  classes = signal<any[]>([]);
  user = signal<AuthUser | null>(null);
  userRole= signal<string>('');

  selectedFee = signal<any | null>(null);
  anchorSelector = signal<string>('');

  constructor(
    @Inject(PLATFORM_ID) private platformId: Object,
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService,
    private readonly authService: AuthService,
    private route: ActivatedRoute,
  ) {
    if(isPlatformBrowser(this.platformId)){
      this.user.set(this.authService.getAuthSession().user);
      this.userRole.set(this.route.snapshot.data['user']);
      console.log(this.userRole(), "this is the user role");
    }
  }

    ngOnInit(): void {
    this.isLoading.set(true);
    if(isPlatformBrowser(this.platformId)){
      const feesData$ = forkJoin({
        classes: this.apiSrv.get('/backend/school/classes')
          .pipe(catchError((err) => { this.toastSrv.error('Error fetching classes', 'Error'); return of([]); })),
        fees: this.apiSrv.get('/backend/payments/fee-structures')
          .pipe(catchError((err) => { this.toastSrv.error('Error fetching fee structures', 'Error'); return of([]); }))
      })
        .subscribe({
          next: (data) => {
            console.log(data.classes,  "here is classes");
            this.fees.set(data.fees);
            this.classes.set(this.utilSrv.configureForOption(data.classes))
          },
          error: (error) => {
            this.toastSrv.error("Error fetching fees", "Error");
          },
          complete: () => {
            this.isLoading.set(false);
          }
        })
    }

    }

    onPreview(evt: { row: any; anchorSelector: string }) {
      this.selectedFee.set(evt.row);
      this.anchorSelector.set(evt.anchorSelector || '');
    }
}
