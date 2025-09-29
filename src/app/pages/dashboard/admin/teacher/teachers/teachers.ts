import { Component } from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {Router} from "@angular/router";

@Component({
  selector: 'app-teachers',
    imports: [
        DataTable,
        DataTableNumbering,
        PageHeader,
        TableSearch,
        LearnoButton
    ],
  templateUrl: './teachers.html',
  styleUrl: './teachers.css'
})
export class Teachers {
    
    constructor(private router: Router) {
    }

    async clickedHandler() {
        console.log('clicked');
        await this.router.navigate(['/admin/teachers/new']);
    }
}
