import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {Icon} from '../../../../../common/icon/icon';

declare const bootstrap: any;

const LEAD_ROLES = ['school_admin', 'tutor_admin', 'super_admin'];
const STATUS_COLOR: Record<string, string> = {reported: 'warning', under_review: 'info', escalated: 'danger', closed: 'secondary'};
const STATUS_LABEL: Record<string, string> = {reported: 'Reported', under_review: 'Under review', escalated: 'Escalated', closed: 'Closed'};
const CATEGORIES = ['welfare', 'bullying', 'abuse', 'attendance', 'mental_health', 'other'];

@Component({
  selector: 'app-safeguarding',
  standalone: true,
  imports: [Icon, PageHeader, LearnoModal, LearnoButton, DataGrid, ReactiveFormsModule, FormsModule, DatePipe],
  templateUrl: './safeguarding.html',
  styleUrl: './safeguarding.css',
})
export class Safeguarding {
  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  readonly isLead = computed(() => LEAD_ROLES.includes(this.auth.getAuthSession()?.user?.role ?? ''));
  readonly categories = CATEGORIES;

  reporting = signal(false);
  students = signal<any[]>([]);
  manageCase = signal<any | null>(null);
  mStatus = signal('');
  mOutcome = signal('');
  busy = signal(false);

  reportForm = new FormGroup({
    category: new FormControl('welfare', {nonNullable: true}),
    summary: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    details: new FormControl(''),
    studentId: new FormControl<number | null>(null),
  });

  columns: GridColumn[] = [
    {key: 'category', label: 'Category', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: 'secondary'})},
    {key: 'summary', label: 'Summary'},
    {key: 'student', label: 'Student'},
    {key: 'reported_by', label: 'Reported by'},
    {key: 'status', label: 'Status', type: 'badge', badge: (v) => ({text: STATUS_LABEL[v] ?? v, color: STATUS_COLOR[v] ?? 'secondary'})},
  ];

  readonly filterDefs: GridFilter[] = [
    {key: 'status', label: 'Status', options: Object.entries(STATUS_LABEL).map(([value, label]) => ({label, value}))},
    {key: 'category', label: 'Category', options: CATEGORIES.map((c) => ({label: this.titleCase(c), value: c}))},
  ];

  constructor() {
    this.api.get<any>('/backend/school/students').subscribe({
      next: (r) => this.students.set(Array.isArray(r) ? r : (r?.data ?? [])),
      error: () => {}, // teachers may not have student list access; report still works without it
    });
  }

  openReport(): void {
    this.reportForm.reset({category: 'welfare'});
    const el = document.getElementById('report_concern');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  submitReport(): void {
    if (this.reportForm.get('summary')!.invalid) { this.toast.error('A summary is required'); return; }
    const v = this.reportForm.value;
    this.reporting.set(true);
    this.api.post<any>('/backend/safeguarding/cases', {
      category: v.category, summary: v.summary, details: v.details, student_id: v.studentId,
    }).subscribe({
      next: (res) => {
        this.toast.success(`Concern reported (${res?.reference ?? 'received'}). Thank you.`);
        this.reporting.set(false);
        const el = document.getElementById('report_concern');
        if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
        this.grid?.refresh();
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not submit'); this.reporting.set(false); },
    });
  }

  onManage(row: any): void {
    this.manageCase.set(row);
    this.mStatus.set(row.status);
    this.mOutcome.set(row.outcome ?? '');
    const el = document.getElementById('manage_case');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  saveCase(): void {
    const c = this.manageCase();
    if (!c) return;
    this.busy.set(true);
    this.api.put<any>('/backend/safeguarding/cases', {id: c.id, status: this.mStatus(), outcome: this.mOutcome()}).subscribe({
      next: (res) => {
        this.toast.success('Case updated');
        this.manageCase.set(res);
        this.busy.set(false);
        this.grid?.refresh();
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Update failed'); this.busy.set(false); },
    });
  }

  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace('_', ' ') : ''; }
}
