import {Component, OnInit, signal} from '@angular/core';
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

@Component({
  selector: 'app-school-classes',
  imports: [
    DataTable,
    LearnoButton,
    PageHeader,
    TableSearch,
    LearnoModal,
    RoutineForm,
    SchoolClassForm,
    Loader
  ],
  templateUrl: './school-classes.html',
  styleUrl: './school-classes.css'
})
export class SchoolClasses implements OnInit{

  isLoading = signal(false);
  sclasses = signal<any[]>([]);

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

}
