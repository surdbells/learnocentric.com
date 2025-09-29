import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {TableSearch} from '../../../../../components/table-search/table-search';
import {DataTable} from '../../../../../components/data-table/data-table';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RoutineForm} from '../../../../../components/forms/routine-form/routine-form';
import {ELibraryForm} from '../../../../../components/forms/e-library-form/e-library-form';
import {ActivatedRoute} from '@angular/router';

@Component({
  selector: 'app-e-library',
  imports: [
    PageHeader,
    LearnoButton,
    TableSearch,
    DataTable,
    LearnoModal,
    RoutineForm,
    ELibraryForm
  ],
  templateUrl: './e-library.html',
  styleUrl: './e-library.css'
})
export class ELibrary {

  userRole: string;

  constructor(private route: ActivatedRoute) {
    this.userRole = this.route.snapshot.data['user'];
  }

  clickhandker() {

  }
}
