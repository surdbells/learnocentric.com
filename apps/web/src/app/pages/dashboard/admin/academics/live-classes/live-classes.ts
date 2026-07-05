import {Component, computed, inject, signal, ViewChild} from '@angular/core';
import {DatePipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {DataGrid, GridColumn, GridFilter} from '../../../../../components/data-grid/data-grid';
import {LiveClassForm} from '../../../../../components/forms/live-class-form/live-class-form';
import {LiveRoom} from '../../../../../common/live-room/live-room';
import {ApiService} from '../../../../../common/service/api.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {scheduled: 'info', live: 'success', ended: 'secondary', cancelled: 'dark'};

@Component({
  selector: 'app-live-classes',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, DataGrid, LiveClassForm, LiveRoom, DatePipe],
  templateUrl: './live-classes.html',
  styleUrl: './live-classes.css',
})
export class LiveClasses {
  @ViewChild(DataGrid) grid!: DataGrid;

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  selectClass = signal<any | null>(null);
  manage = signal<any | null>(null);
  attendance = signal<any[]>([]);
  summary = signal<any | null>(null);
  busy = signal(false);
  subjects = signal<any[]>([]);
  classes = signal<any[]>([]);
  topics = signal<any[]>([]);
  room = signal<{ roomUrl: string; token: string | null; title: string } | null>(null);

  columns: GridColumn[] = [
    {key: 'title', label: 'Class', sortable: true},
    {key: 'subject', label: 'Subject'},
    {key: 'class_label', label: 'Group'},
    {key: 'scheduled_at', label: 'Starts', type: 'date'},
    {key: 'status', label: 'Status', type: 'badge', badge: (v) => ({text: this.titleCase(v), color: STATUS_COLOR[v] ?? 'secondary'})},
    {key: 'host', label: 'Host'},
  ];

  readonly filterDefs = computed<GridFilter[]>(() => [
    {key: 'status', label: 'Status', options: [
      {label: 'Scheduled', value: 'scheduled'}, {label: 'Live', value: 'live'}, {label: 'Ended', value: 'ended'}, {label: 'Cancelled', value: 'cancelled'}]},
    {key: 'subject_id', label: 'Subject', options: this.subjects().map((s) => ({label: s.name, value: String(s.id)}))},
  ]);

  constructor() {
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/school/classes').subscribe({next: (r) => this.classes.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? []))});
  }

  onAdd(): void { this.selectClass.set(null); }

  onEdit(row: any): void {
    this.selectClass.set(row);
    this.open('add_live_class');
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    this.close('add_live_class');
    this.selectClass.set(null);
    this.grid?.refresh();
  }

  onView(row: any): void {
    this.manage.set(row);
    this.attendance.set([]);
    this.summary.set(null);
    this.loadAttendance(row.id);
    this.open('live_manage');
  }

  private loadAttendance(id: number): void {
    this.api.get<any>(`/backend/live-classes/${id}/attendance`).subscribe({
      next: (res) => { this.attendance.set(res?.data ?? []); this.summary.set(res?.summary ?? null); this.manage.set(res?.live_class ?? this.manage()); },
    });
  }

  setStatus(action: 'start' | 'end'): void {
    const lc = this.manage();
    if (!lc) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/live-classes/${lc.id}/${action}`, {}).subscribe({
      next: (res) => {
        this.toast.success(action === 'start' ? 'Class is now live' : 'Class ended');
        this.manage.set(res);
        this.grid?.refresh();
        this.busy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Action failed'); this.busy.set(false); },
    });
  }

  /** Host the class in-app (embedded Daily Prebuilt with owner privileges). */
  hostRoom(): void {
    const lc = this.manage();
    if (!lc) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/live-classes/${lc.id}/join`, {}).subscribe({
      next: (res) => {
        this.busy.set(false);
        if (res?.room_url) {
          this.close('live_manage');
          this.room.set({roomUrl: res.room_url, token: res.token ?? null, title: res.title || lc.title});
        } else {
          this.toast.error('This class has no room yet.');
        }
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not open the room'); this.busy.set(false); },
    });
  }

  leaveRoom(): void {
    this.room.set(null);
    this.grid?.refresh();
  }

  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  private open(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  private close(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
  }
}
