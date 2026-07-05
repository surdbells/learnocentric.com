import {Component, signal, ViewChild} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {SubjectForm} from '../../../../../components/forms/subject-form/subject-form';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';

declare const bootstrap: any;

@Component({
  selector: 'app-subjects',
  standalone: true,
  imports: [PageHeader, LearnoModal, SubjectForm, LearnoButton, DataGrid],
  templateUrl: './subjects.html',
  styleUrl: './subjects.css',
})
export class Subjects {
  @ViewChild(DataGrid) grid!: DataGrid;

  selectSubject = signal<any | null>(null);

  columns: GridColumn[] = [
    {key: 'name', label: 'Name', sortable: true},
    {key: 'code', label: 'Code', sortable: true},
    {key: 'class_label', label: 'Class'},
    {key: 'status', label: 'Status', type: 'badge'},
  ];

  filterDefs: GridFilter[] = [
    {key: 'status', label: 'Status', options: [{label: 'Active', value: 'active'}, {label: 'Inactive', value: 'inactive'}]},
  ];

  onAdd(): void {
    this.selectSubject.set(null);
  }

  onEdit(row: any): void {
    this.selectSubject.set(row);
    const el = document.getElementById('add_subject');
    if (el && typeof bootstrap !== 'undefined') {
      bootstrap.Modal.getOrCreateInstance(el).show();
    }
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    const el = document.getElementById('add_subject');
    if (el && typeof bootstrap !== 'undefined') {
      bootstrap.Modal.getInstance(el)?.hide();
    }
    this.selectSubject.set(null);
    this.grid?.refresh();
  }
}
