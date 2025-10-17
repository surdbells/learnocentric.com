import {Component, Inject, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {TableSearch} from '../../../../../components/table-search/table-search';
import {DataTable} from '../../../../../components/data-table/data-table';
import {DataTableNumbering} from '../../../../../components/data-table-numbering/data-table-numbering';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {isPlatformBrowser} from '@angular/common';
import {forkJoin} from 'rxjs';
import {FeesForm} from '../../../../../components/forms/fees-form/fees-form';
import {UtilService} from '../../../../../common/service/util.service';
import {Loader} from '../../../../../common/loader/loader';

@Component({
  selector: 'app-fees',
  imports: [
    PageHeader,
    TableSearch,
    DataTable,
    DataTableNumbering,
    LearnoModal,
    RoutineForm,
    FeesForm,
    Loader
  ],
  templateUrl: './fees.html',
  styleUrl: './fees.css'
})
export class Fees implements OnInit{

  isLoading = signal(false);
  fees = signal<any[]>([]);
  classes = signal<any[]>([]);

  constructor(
    @Inject(PLATFORM_ID) private platformId: Object,
    private readonly apiSrv: ApiService,
    private readonly toastSrv: ToastrService,
    private readonly utilSrv: UtilService
  ) { }

    ngOnInit(): void {

    this.isLoading.set(true);
    if(isPlatformBrowser(this.platformId)){
      const feesData$ = forkJoin({
        classes: this.apiSrv.get('/backend/school/classes'),
        fees: this.apiSrv.get('/backend/payments/fee-structures')
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

}
