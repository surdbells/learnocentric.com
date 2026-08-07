import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {InterventionForm} from '../../../../../components/forms/intervention-form/intervention-form';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {KpiStrip, KpiItem, DonutChart, DonutSegment, BarList, BarItem} from '../../../../../common/ui';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {open: 'warning', in_progress: 'info', resolved: 'success'};
const STATUS_LABEL: Record<string, string> = {open: 'Open', in_progress: 'In Progress', resolved: 'Resolved'};
const PRIORITY_TONE: Record<string, string> = {high: 'danger', medium: 'warning', low: 'secondary'};
const TONES = ['primary', 'success', 'warning', 'info', 'danger', 'secondary'];

/**
 * School-Admin Interventions workspace (design: Interventions_SA) — KPI strip,
 * status tabs, a rich support-plan table (learner/class/concern/type/staff/
 * status/priority/next-review/progress), a summary + attention rail, and
 * concern/type distributions. Backed by /school/interventions/board (real).
 * Create/edit reuse the existing InterventionForm.
 */
@Component({
  selector: 'app-interventions',
  standalone: true,
  imports: [PageHeader, LearnoModal, InterventionForm, LearnoButton, Icon, DatePipe, FormsModule, KpiStrip, DonutChart, BarList],
  templateUrl: './interventions.html',
  styleUrl: './interventions.css',
})
export class Interventions {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  board = signal<any>(null);
  selectIntervention = signal<any | null>(null);

  students = signal<any[]>([]);
  subjects = signal<any[]>([]);
  topics = signal<any[]>([]);
  teachers = signal<any[]>([]);

  activeTab = signal<'active' | 'overdue' | 'resolved' | 'all'>('active');
  search = signal('');

  constructor() {
    this.load();
    this.api.get<any>('/backend/school/students').subscribe({next: (r) => this.students.set(this.list(r))});
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(this.list(r))});
    this.api.get<any>('/backend/curriculum/topics').subscribe({next: (r) => this.topics.set(this.list(r))});
    this.api.get<any>('/backend/school/teachers').subscribe({next: (r) => this.teachers.set(this.list(r))});
  }

  private list(r: any): any[] { return Array.isArray(r) ? r : (r?.data ?? []); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/school/interventions/board').subscribe({
      next: (res) => { this.board.set(res ?? {}); this.loading.set(false); },
      error: (e) => { this.loading.set(false); this.toast.error(e?.error?.error || 'Could not load interventions'); },
    });
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const k = this.board()?.kpis ?? {};
    return [
      {label: 'Learners Flagged', value: k.learners_flagged ?? 0, icon: 'flag', tone: 'primary'},
      {label: 'Active Interventions', value: k.active ?? 0, icon: 'assignment', tone: 'info'},
      {label: 'Overdue Follow-ups', value: k.overdue_followups ?? 0, icon: 'schedule', tone: (k.overdue_followups ? 'danger' : 'secondary')},
      {label: 'Resolved Cases', value: k.resolved ?? 0, icon: 'check_circle', tone: 'success'},
      {label: 'Success Rate', value: k.success_rate == null ? '—' : k.success_rate + '%', icon: 'trending_up', tone: 'primary'},
    ];
  });

  readonly all = computed<any[]>(() => this.board()?.interventions ?? []);

  readonly tabs = computed(() => {
    const a = this.all();
    return [
      {key: 'active' as const, label: 'Active', count: a.filter(i => i.status !== 'resolved').length},
      {key: 'overdue' as const, label: 'Overdue', count: a.filter(i => i.overdue).length},
      {key: 'resolved' as const, label: 'Resolved', count: a.filter(i => i.status === 'resolved').length},
      {key: 'all' as const, label: 'All', count: a.length},
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), q = this.search().toLowerCase().trim();
    let rows = this.all();
    if (t === 'active') rows = rows.filter(i => i.status !== 'resolved');
    else if (t === 'overdue') rows = rows.filter(i => i.overdue);
    else if (t === 'resolved') rows = rows.filter(i => i.status === 'resolved');
    if (q) rows = rows.filter(i => `${i.student} ${i.reason} ${i.type ?? ''} ${i.assigned_to ?? ''} ${i.class_label ?? ''}`.toLowerCase().includes(q));
    return rows;
  });

  readonly donut = computed<DonutSegment[]>(() =>
    (this.board()?.distributions?.by_type ?? []).map((d: any, idx: number) => ({label: d.label, value: d.value, tone: TONES[idx % TONES.length]})));

  readonly hasDonut = computed(() => this.donut().reduce((a, d) => a + d.value, 0) > 0);

  readonly classBars = computed<BarItem[]>(() =>
    (this.board()?.distributions?.by_class ?? []).map((d: any, idx: number) => ({label: d.label, value: d.value, tone: TONES[idx % TONES.length]})));

  onAdd(): void { this.selectIntervention.set(null); }

  onEdit(row: any): void {
    this.selectIntervention.set(row);
    this.open('add_intervention');
  }

  handleSuccessSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    this.close('add_intervention');
    this.selectIntervention.set(null);
    this.load();
  }

  resolve(row: any): void {
    this.api.put<any>('/backend/school/interventions', {id: row.id, status: 'resolved', progress: 100}).subscribe({
      next: () => { this.toast.success('Marked resolved'); this.load(); },
      error: (e) => this.toast.error(e?.error?.error || 'Update failed'),
    });
  }

  remove(row: any): void {
    this.api.delete<any>(`/backend/school/interventions?id=${row.id}`, {confirm: `Delete the intervention for ${row.student}?`}).subscribe({
      next: () => { this.toast.success('Deleted'); this.load(); },
      error: (e) => this.toast.error(e?.error?.error || 'Delete failed'),
    });
  }

  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }
  statusLabel(s: string): string { return STATUS_LABEL[s] ?? s; }
  priorityTone(p: string): string { return PRIORITY_TONE[p] ?? 'secondary'; }

  private open(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }
  private close(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
  }
}
