import {Component, signal, ViewChild} from '@angular/core';
import {RouterLink} from '@angular/router';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {EditStudentForm} from '../../../../../components/forms/edit-student-form/edit-student-form';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';

declare const bootstrap: any;

@Component({
  selector: 'app-students',
  standalone: true,
  imports: [PageHeader, LearnoModal, EditStudentForm, LearnoButton, DataGrid, RouterLink],
  templateUrl: './students.html',
  styleUrl: './students.css',
})
export class Students {
  @ViewChild(DataGrid) grid!: DataGrid;

  selected = signal<any | null>(null);

  columns: GridColumn[] = [
    {key: 'email', label: 'Email', sortable: true},
    {key: 'first_name', label: 'First Name', sortable: true},
    {key: 'last_name', label: 'Last Name', sortable: true},
    {key: 'is_active', label: 'Status', type: 'badge', badge: v => ({text: v ? 'Active' : 'Suspended', color: v ? 'success' : 'secondary'})},
  ];

  filterDefs: GridFilter[] = [
    {key: 'is_active', label: 'Status', options: [{label: 'Active', value: '1'}, {label: 'Suspended', value: '0'}]},
  ];

  onEdit(row: any): void {
    this.selected.set(row);
    const el = document.getElementById('edit_student');
    if (el && typeof bootstrap !== 'undefined') {
      bootstrap.Modal.getOrCreateInstance(el).show();
    }
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    const el = document.getElementById('edit_student');
    if (el && typeof bootstrap !== 'undefined') {
      bootstrap.Modal.getInstance(el)?.hide();
    }
    this.selected.set(null);
    this.grid?.refresh();
  }
}
