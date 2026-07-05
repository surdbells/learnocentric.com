import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {InterventionForm} from '../../../../../components/forms/intervention-form/intervention-form';
import {ApiService} from '../../../../../common/service/api.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {open: 'warning', in_progress: 'info', resolved: 'success'};
const STATUS_LABEL: Record<string, string> = {open: 'Open', in_progress: 'In progress', resolved: 'Resolved'};

@Component({
  selector: 'app-interventions',
  standalone: true,
  imports: [PageHeader, LearnoModal, InterventionForm, LearnoButton, DataGrid],
  templateUrl: './interventions.html',
  styleUrl: './interventions.css',
})
export class Interventions {
  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);

  selectIntervention = signal<any | null>(null);
  students = signal<any[]>([]);
  subjects = signal<any[]>([]);
  topics = signal<any[]>([]);
  teachers = signal<any[]>([]);

  columns: GridColumn[] = [
    {key: 'student', label: 'Student'},
    {key: 'reason', label: 'Concern'},
    {key: 'subject', label: 'Subject'},
    {key: 'assigned_to', label: 'Assigned to'},
    {key: 'due_date', label: 'Due', type: 'date'},
    {key: 'status', label: 'Status', type: 'badge', badge: (v) => ({text: STATUS_LABEL[v] ?? v, color: STATUS_COLOR[v] ?? 'secondary'})},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'status', label: 'Status', options: [{label: 'Open', value: 'open'}, {label: 'In progress', value: 'in_progress'}, {label: 'Resolved', value: 'resolved'}]},
    {key: 'subject_id', label: 'Subject', options: this.subjects().map((s) => ({label: s.name, value: String(s.id)}))},
    {key: 'assigned_to', label: 'Assigned to', options: this.teachers().map((t) => ({label: `${t.first_name} ${t.last_name}`, value: String(t.id)}))},
  ]);

  constructor() {
    this.api.get<any>('/backend/school/students').subscribe({next: (r) => this.students.set(this.list(r))});
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(this.list(r))});
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(this.list(r))});
    this.api.get<any>('/backend/school/teachers').subscribe({next: (r) => this.teachers.set(this.list(r))});
  }

  private list(r: any): any[] { return Array.isArray(r) ? r : (r?.data ?? []); }

  onAdd(): void { this.selectIntervention.set(null); }

  onEdit(row: any): void {
    this.selectIntervention.set(row);
    const el = document.getElementById('add_intervention');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    const el = document.getElementById('add_intervention');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
    this.selectIntervention.set(null);
    this.grid?.refresh();
  }
}
