import {Component, signal, ViewChild} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {SchoolClassForm} from '../../../../../components/forms/school-class-form/school-class-form';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';

declare const bootstrap: any;

@Component({
  selector: 'app-school-classes',
  standalone: true,
  imports: [PageHeader, LearnoModal, SchoolClassForm, LearnoButton, DataGrid],
  templateUrl: './school-classes.html',
  styleUrl: './school-classes.css',
})
export class SchoolClasses {
  @ViewChild(DataGrid) grid!: DataGrid;

  selectedClass = signal<any | null>(null);

  columns: GridColumn[] = [
    {key: 'name', label: 'Class', sortable: true},
    {key: 'grade_level', label: 'Level', sortable: true},
    {key: 'class_teacher', label: 'Class Teacher'},
    {key: 'status', label: 'Status', type: 'badge'},
  ];

  filterDefs: GridFilter[] = [
    {key: 'status', label: 'Status', options: [{label: 'Active', value: 'active'}, {label: 'Inactive', value: 'inactive'}]},
  ];

  onAdd(): void {
    this.selectedClass.set(null);
  }

  onEdit(row: any): void {
    this.selectedClass.set(row);
    const el = document.getElementById('add_class');
    if (el && typeof bootstrap !== 'undefined') {
      bootstrap.Modal.getOrCreateInstance(el).show();
    }
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    const el = document.getElementById('add_class');
    if (el && typeof bootstrap !== 'undefined') {
      bootstrap.Modal.getInstance(el)?.hide();
    }
    this.selectedClass.set(null);
    this.grid?.refresh();
  }
}
