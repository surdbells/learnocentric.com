import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {LiveClassForm} from '../../../../../components/forms/live-class-form/live-class-form';
import {LiveRoom} from '../../../../../common/live-room/live-room';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiStrip, KpiItem, DonutChart, DonutSegment} from '../../../../../common/ui';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {scheduled: 'info', live: 'success', ended: 'secondary', cancelled: 'dark'};
const STATUS_LABEL: Record<string, string> = {scheduled: 'Upcoming', live: 'Live Now', ended: 'Completed', cancelled: 'Cancelled'};

/**
 * Teacher/School-Admin Live Classes workspace (design: Live Classes_TD), KPI
 * strip, a scheduled-classes table with per-class attendance, an attendance
 * snapshot donut, a Today's-Schedule rail and quick actions. Backed by
 * /live-classes/staff-board (real classes + attendance). Scheduling, running
 * (go-live/end), in-app hosting and per-class attendance reuse the existing
 * modals. The design's per-class recordings/materials have no data model, so
 * that column/panel is omitted rather than fabricated.
 */
@Component({
  selector: 'app-live-classes',
  standalone: true,
  imports: [Icon, PageHeader, LearnoModal, LearnoButton, LiveClassForm, LiveRoom, DatePipe, FormsModule, KpiStrip, DonutChart],
  templateUrl: './live-classes.html',
  styleUrl: './live-classes.css',
})
export class LiveClasses {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  board = signal<any>(null);
  selectClass = signal<any | null>(null);
  manage = signal<any | null>(null);
  attendance = signal<any[]>([]);
  summary = signal<any | null>(null);
  busy = signal(false);
  subjects = signal<any[]>([]);
  classes = signal<any[]>([]);
  topics = signal<any[]>([]);
  room = signal<{ appId: string; channel: string; token: string | null; uid: number; isHost: boolean; title: string } | null>(null);

  // filters
  statusFilter = signal('all');
  subjectFilter = signal('all');
  search = signal('');

  readonly kpis = computed<KpiItem[]>(() => {
    const k = this.board()?.kpis ?? {};
    return [
      {label: 'Total Live Classes', value: k.total ?? 0, icon: 'video_camera_front', tone: 'primary'},
      {label: 'This Week', value: k.this_week ?? 0, icon: 'date_range', tone: 'info'},
      {label: 'Upcoming Today', value: k.upcoming_today ?? 0, icon: 'schedule', tone: 'warning'},
      {label: 'Completed (week)', value: k.completed_this_week ?? 0, icon: 'check_circle', tone: 'success'},
      {label: 'Attendance Rate', value: k.attendance_rate == null ? '-' : k.attendance_rate + '%', icon: 'groups', tone: 'primary'},
    ];
  });

  readonly donut = computed<DonutSegment[]>(() => {
    const s = this.board()?.snapshot ?? {};
    const segs: DonutSegment[] = [
      {label: 'Present', value: s.present ?? 0, tone: 'success'},
      {label: 'Absent', value: s.absent ?? 0, tone: 'danger'},
    ];
    return segs.filter(x => x.value > 0);
  });

  readonly hasSnapshot = computed(() => this.donut().reduce((a, d) => a + d.value, 0) > 0);

  readonly subjectOptions = computed<string[]>(() => {
    const set = new Set<string>();
    (this.board()?.classes ?? []).forEach((c: any) => c.subject && set.add(c.subject));
    return [...set].sort();
  });

  readonly filtered = computed<any[]>(() => {
    const q = this.search().toLowerCase().trim();
    const st = this.statusFilter(), subj = this.subjectFilter();
    return (this.board()?.classes ?? []).filter((c: any) => {
      if (st !== 'all' && c.status !== st) return false;
      if (subj !== 'all' && c.subject !== subj) return false;
      if (q && !(`${c.title} ${c.subject} ${c.topic ?? ''} ${c.class_label ?? ''}`.toLowerCase().includes(q))) return false;
      return true;
    });
  });

  readonly today = computed<any[]>(() => this.board()?.today ?? []);

  constructor() {
    this.load();
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/school/classes').subscribe({next: (r) => this.classes.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(Array.isArray(r) ? r : (r?.data ?? []))});
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/live-classes/staff-board').subscribe({
      next: (res) => { this.board.set(res ?? {}); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load live classes'); },
    });
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
    this.load();
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
        this.load();
        this.busy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Action failed'); this.busy.set(false); },
    });
  }

  /** Host the class in-app (Agora call as the host). */
  hostRoom(): void {
    const lc = this.manage();
    if (!lc) return;
    this.busy.set(true);
    this.api.post<any>(`/backend/live-classes/${lc.id}/join`, {}).subscribe({
      next: (res) => {
        this.busy.set(false);
        if (res?.channel) {
          this.close('live_manage');
          this.room.set({appId: res.app_id, channel: res.channel, token: res.token ?? null, uid: res.uid ?? 0, isHost: res.is_host ?? true, title: res.title || lc.title});
        } else {
          this.toast.error('This class has no channel yet.');
        }
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not open the room'); this.busy.set(false); },
    });
  }

  remove(row: any): void {
    this.api.delete<any>(`/backend/live-classes?id=${row.id}`, {confirm: `Delete the live class “${row.title}”? This cannot be undone.`}).subscribe({
      next: () => { this.toast.success('Live class deleted'); this.load(); },
      error: (e) => this.toast.error(e?.error?.error || 'Delete failed'),
    });
  }

  leaveRoom(): void {
    this.room.set(null);
    this.load();
  }

  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }
  statusLabel(s: string): string { return STATUS_LABEL[s] ?? this.titleCase(s); }
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
