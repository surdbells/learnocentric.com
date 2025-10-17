import {Component, signal} from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {Router} from "@angular/router";
import {ApiService} from '../../../../../common/service/api.service';
import {Loader} from '../../../../../common/loader/loader';

@Component({
  selector: 'app-teachers',
  imports: [
    DataTable,
    DataTableNumbering,
    PageHeader,
    TableSearch,
    LearnoButton,
    Loader
  ],
  templateUrl: './teachers.html',
  styleUrl: './teachers.css'
})
export class Teachers {

  isLoading = signal(false);
  teachers = signal<any[]>([]);

  constructor(
    private router: Router,
    private readonly apiSrv: ApiService,
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
}
