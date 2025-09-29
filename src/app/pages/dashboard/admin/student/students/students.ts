import { Component } from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";
import {DataTable} from "../../../../../components/data-table/data-table";
import {DataTableNumbering} from "../../../../../components/data-table-numbering/data-table-numbering";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {Router} from "@angular/router";

@Component({
  selector: 'app-students',
    imports: [
        PageHeader,
        TableSearch,
        DataTable,
        DataTableNumbering,
        LearnoButton
    ],
  templateUrl: './students.html',
  styleUrl: './students.css'
})
export class Students {
    
    constructor(private router: Router) {
    }

    async clickedHandler() {
        await this.router.navigate(['/admin/students/new']);
    }
}
    