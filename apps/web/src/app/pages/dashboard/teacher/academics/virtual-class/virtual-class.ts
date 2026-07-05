import { Component } from '@angular/core';
import {DataTable} from "../../../../../components/data-table/data-table";
import {LearnoModal} from "../../../../../components/learno-modal/learno-modal";
import {LessonPlanForm} from "../../../../../components/forms/lesson-plan-form/lesson-plan-form";
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TableSearch} from "../../../../../components/table-search/table-search";

@Component({
  selector: 'app-virtual-class',
    imports: [
        DataTable,
        LearnoModal,
        LessonPlanForm,
        PageHeader,
        TableSearch
    ],
  templateUrl: './virtual-class.html',
  styleUrl: './virtual-class.css'
})
export class VirtualClass {

}
