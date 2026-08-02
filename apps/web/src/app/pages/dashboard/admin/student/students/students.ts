import {Component, computed, inject, OnInit, signal, ViewChild} from '@angular/core';
import {RouterLink} from '@angular/router';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {EditStudentForm} from '../../../../../components/forms/edit-student-form/edit-student-form';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {ApiService} from '../../../../../common/service/api.service';
import {KpiItem, KpiStrip} from '../../../../../common/ui';

declare const bootstrap: any;

@Component({
  selector: 'app-students',
  standalone: true,
  imports: [PageHeader, LearnoModal, EditStudentForm, LearnoButton, DataGrid, RouterLink, KpiStrip],
  templateUrl: './students.html',
  styleUrl: './students.css',
})
export class Students implements OnInit {
  @ViewChild(DataGrid) grid!: DataGrid;
  private readonly api = inject(ApiService);

  selected = signal<any | null>(null);
  allStudents = signal<any[]>([]);

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.allStudents();
    const active = s.filter(x => x.is_active).length;
    return [
      {label: 'Total students', value: s.length, icon: 'group', tone: 'primary'},
      {label: 'Active', value: active, icon: 'check_circle', tone: 'success'},
      {label: 'Suspended', value: s.length - active, icon: 'cancel', tone: (s.length - active) ? 'warning' : 'secondary'},
    ];
  });

  ngOnInit(): void { this.loadCounts(); }

  private loadCounts(): void {
    this.api.get<any>('/backend/school/students?paginated=false').subscribe({
      next: (r) => this.allStudents.set(Array.isArray(r) ? r : (r?.data ?? [])),
      error: () => {},
    });
  }

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
    this.loadCounts();
  }
}
