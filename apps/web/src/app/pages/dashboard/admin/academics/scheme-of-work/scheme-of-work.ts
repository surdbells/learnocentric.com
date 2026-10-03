import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {SchemeForm} from '../../../../../components/forms/scheme-form/scheme-form';
import {ApiService} from '../../../../../common/service/api.service';

declare const bootstrap: any;

@Component({
  selector: 'app-scheme-of-work',
  standalone: true,
  imports: [PageHeader, LearnoModal, SchemeForm, LearnoButton, DataGrid],
  templateUrl: './scheme-of-work.html',
  styleUrl: './scheme-of-work.css',
})
export class SchemeOfWork {
  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);

  selectScheme = signal<any | null>(null);
  classes = signal<any[]>([]);
  subjects = signal<any[]>([]);
  terms = signal<any[]>([]);
  topics = signal<any[]>([]);
  teachers = signal<any[]>([]);

  columns: GridColumn[] = [
    {key: 'week_number', label: 'Week', sortable: true},
    {key: 'topic', label: 'Topic'},
    {key: 'class_label', label: 'Class'},
    {key: 'subject', label: 'Subject'},
    {key: 'objective', label: 'Objective'},
    {key: 'status', label: 'Status', type: 'badge'},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'class_id', label: 'Class', options: this.classes().map((c) => ({label: c.label || c.name, value: String(c.id)}))},
    {key: 'subject_id', label: 'Subject', options: this.subjects().map((s) => ({label: s.name, value: String(s.id)}))},
    {key: 'status', label: 'Status', options: [{label: 'Draft', value: 'draft'}, {label: 'Published', value: 'published'}]},
  ]);

  constructor() {
    this.api.get<any>('/backend/school/classes').subscribe({next: (r) => this.classes.set(this.list(r))});
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(this.list(r))});
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(this.list(r))});
    this.api.get<any>('/backend/school/teachers').subscribe({next: (r) => this.teachers.set(this.list(r))});
    this.api.get<any>('/backend/school/terms').subscribe({
      next: (r) => this.terms.set(this.list(r)),
      error: () => this.terms.set([]),
    });
  }

  private list(r: any): any[] { return Array.isArray(r) ? r : (r?.data ?? []); }

  onAdd(): void { this.selectScheme.set(null); }

  onEdit(row: any): void {
    this.selectScheme.set(row);
    const el = document.getElementById('add_week');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    const el = document.getElementById('add_week');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
    this.selectScheme.set(null);
    this.grid?.refresh();
  }
}
