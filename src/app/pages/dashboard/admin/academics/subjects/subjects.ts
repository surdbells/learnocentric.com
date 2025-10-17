import {Component, OnInit, signal} from '@angular/core';
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

@Component({
  selector: 'app-subjects',
  imports: [
    DataTable,
    PageHeader,
    TableSearch,
    LearnoModal,
    SubjectForm,
    Loader,
    DataTableNumbering
  ],
  templateUrl: './subjects.html',
  styleUrl: './subjects.css'
})
export class Subjects implements OnInit{
  isLoading = signal(false);
  subjects = signal<any[]>([]);

  constructor(
    private readonly apiService: ApiService,
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
}
