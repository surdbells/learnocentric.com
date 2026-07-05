import {Component, Inject, PLATFORM_ID, signal, ViewChild} from '@angular/core';
import {CurrencyPipe, isPlatformBrowser, NgIf} from "@angular/common";
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {FeesForm} from "../../../../../components/forms/fees-form/fees-form";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {LearnoModal} from "../../../../../components/learno-modal/learno-modal";
import {LearnoOffset} from "../../../../../components/learno-offset/learno-offset";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {SkeletonLoader} from "../../../../../common/skeleton-loader/skeleton-loader";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {AuthUser} from '../../../../../common/auth/auth.models';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {UtilService} from '../../../../../common/service/util.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ActivatedRoute} from '@angular/router';
import {catchError, forkJoin, of} from 'rxjs';
import {PaymentForm} from '../../../../../components/forms/payment-form/payment-form';

@Component({
  selector: 'app-payment',
  imports: [
    CurrencyPipe,
    DataTable,
    DataTableNumbering,
    FeesForm,
    LearnoButton,
    LearnoModal,
    LearnoOffset,
    NgIf,
    PageHeader,
    SkeletonLoader,
    TableSearch,
    PaymentForm
  ],
  templateUrl: './student-payment.component.html',
  styleUrl: './student-payment.component.css'
})
export class StudentPayment {
  isLoading = signal(false);
  fees = signal<any[]>([]);
  payments = signal<any[]>([]);
  user = signal<AuthUser | null>(null);

  selectedPay = signal<any | null>(null);
  anchorSelector = signal<string>('');

  @ViewChild(LearnoOffset) offsetCmp!: LearnoOffset;


  constructor(
    @Inject(PLATFORM_ID) private platformId: Object,
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService,
    private readonly authService: AuthService,
  ) {
    if(isPlatformBrowser(this.platformId)){
      this.user.set(this.authService.getAuthSession().user);
    }
  }

  private loadData() {
    this.isLoading.set(true);
    if(isPlatformBrowser(this.platformId)){
      const feesData$ = forkJoin({
        payments: this.apiSrv.get('/backend/payments/list')
          .pipe(catchError((err) => { this.toastSrv.error('Error fetching payment', 'Error'); return of([]); })),
        fees: this.apiSrv.get('/backend/payments/fee-structures')
          .pipe(catchError((err) => { this.toastSrv.error('Error fetching fee structures', 'Error'); return of([]); }))
      })
        .subscribe({
          next: (data) => {
            this.payments.set(data.payments);
            this.fees.set(this.utilSrv.configureForOption(data.fees))
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

  ngOnInit(): void {
    this.loadData();
  }

  onPreview(evt: { row: any; anchorSelector: string }) {
    this.selectedPay.set(evt.row);
    this.anchorSelector.set(evt.anchorSelector || '');
  }

  handleSuccessSubmit($event: { success: boolean }) {
    console.log($event, "this is the success event");
    if ($event.success) {
      this.loadData();
      this.offsetCmp.close();
    }
  }

}
