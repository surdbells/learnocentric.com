import {Component, OnInit, signal} from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {Router} from "@angular/router";
import {ApiService} from '../../../../../common/service/api.service';
import {Loader} from '../../../../../common/loader/loader';

@Component({
  selector: 'app-students',
  imports: [
    PageHeader,
    TableSearch,
    DataTable,
    DataTableNumbering,
    LearnoButton,
    Loader
  ],
  templateUrl: './students.html',
  styleUrl: './students.css'
})
export class Students implements OnInit {

  isLoading = signal(false);
  students = signal<any[]>([]);

  constructor(
    private router: Router,
    private readonly apiSrv: ApiService,
    ) { }

  ngOnInit(): void {
    this.isLoading.set(true);
    this.apiSrv.get("/backend/school/students")
      .subscribe({
        next: (data) => {
          this.students.set(data);
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
        await this.router.navigate(['/admin/students/new']);
    }
}
