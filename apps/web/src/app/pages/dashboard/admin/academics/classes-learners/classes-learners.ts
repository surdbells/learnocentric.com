import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {KpiStrip, KpiItem} from '../../../../../common/ui';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {SchoolClassForm} from '../../../../../components/forms/school-class-form/school-class-form';

declare const bootstrap: any;

/**
 * School-Admin "Classes & Learners" unified hub (design: Classes & Learners_SA)
 *, KPI strip, a class list (with inline create / edit / delete), and the
 * selected class's learner roster with real average scores, risk status and
 * intervention flags, plus a class-overview rail. The separate "Class" page is
 * merged in here (PDF review A3). Backed by /school/classes-learners,
 * /school/classes/{id}/roster and /school/classes CRUD.
 */
@Component({
  selector: 'app-classes-learners',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, RouterLink, KpiStrip, LearnoModal, SchoolClassForm],
  templateUrl: './classes-learners.html',
  styleUrl: './classes-learners.css',
})
export class ClassesLearners {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  loading = signal(true);
  hub = signal<any>(null);
  selectedId = signal<number | null>(null);
  roster = signal<any>(null);
  rosterLoading = signal(false);
  search = signal('');

  /** Raw class rows (for the create/edit form) + the class being edited. */
  rawClasses = signal<any[]>([]);
  selectedClass = signal<any | null>(null);

  /** Route base for links to the dedicated CRUD pages (admin vs academy). */
  readonly root = signal(this.auth.getAuthSession()?.user?.role === 'tutor_admin' ? '/academy' : '/admin');

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/school/classes-learners').subscribe({
      next: (res) => {
        this.hub.set(res ?? {});
        this.loading.set(false);
        const first = (res?.classes ?? [])[0];
        if (first) this.selectClass(first.id);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load classes'); },
    });
    // Raw rows (name / grade level / …) so the edit form can pre-fill.
    this.api.get<any>('/backend/school/classes').subscribe({
      next: (res) => this.rawClasses.set(res?.data ?? res ?? []),
      error: () => {},
    });
  }

  private openClassModal(): void {
    const el = document.getElementById('add_class');
    if (el && typeof bootstrap !== 'undefined') { bootstrap.Modal.getOrCreateInstance(el).show(); }
  }

  /** New class, opens the form empty. */
  onAddClass(): void { this.selectedClass.set(null); this.openClassModal(); }

  /** Edit, pre-fills from the raw class row. */
  onEditClass(c: any, event: Event): void {
    event.stopPropagation();
    this.selectedClass.set(this.rawClasses().find(r => r.id === c.id) ?? {id: c.id, name: c.label});
    this.openClassModal();
  }

  deleteClass(c: any, event: Event): void {
    event.stopPropagation();
    if (!confirm(`Delete class "${c.label}"? This cannot be undone.`)) return;
    this.api.delete(`/backend/school/classes?id=${c.id}`, {confirm: false}).subscribe({
      next: () => { this.toast.success('Class deleted'); this.load(); },
      error: (e: any) => this.toast.error(e?.error?.error || 'Could not delete class'),
    });
  }

  handleClassSubmit(event: { success: boolean }): void {
    if (!event.success) return;
    const el = document.getElementById('add_class');
    if (el && typeof bootstrap !== 'undefined') { bootstrap.Modal.getInstance(el)?.hide(); }
    this.selectedClass.set(null);
    this.load();
  }

  readonly kpis = computed<KpiItem[]>(() => {
    const k = this.hub()?.kpis ?? {};
    return [
      {label: 'Total Classes', value: k.total_classes ?? 0, icon: 'groups', tone: 'primary'},
      {label: 'Total Learners', value: k.total_learners ?? 0, icon: 'school', tone: 'info'},
      {label: 'Active Enrolments', value: k.active_enrollments ?? 0, icon: 'how_to_reg', tone: 'success'},
      {label: 'Avg Class Size', value: k.avg_class_size ?? 0, icon: 'analytics', tone: 'warning'},
    ];
  });

  readonly classes = computed<any[]>(() => this.hub()?.classes ?? []);

  readonly learners = computed<any[]>(() => {
    const q = this.search().toLowerCase().trim();
    const all = this.roster()?.learners ?? [];
    return q ? all.filter((l: any) => l.name.toLowerCase().includes(q) || (l.email ?? '').toLowerCase().includes(q)) : all;
  });

  selectClass(id: number): void {
    this.selectedId.set(id);
    this.rosterLoading.set(true);
    this.roster.set(null);
    this.api.get<any>(`/backend/school/classes/${id}/roster`).subscribe({
      next: (res) => { this.roster.set(res ?? {}); this.rosterLoading.set(false); },
      error: () => { this.rosterLoading.set(false); this.toast.error('Could not load the class roster'); },
    });
  }

  scoreTone(v: number | null): string {
    if (v == null) return 'secondary';
    if (v >= 70) return 'success';
    if (v >= 50) return 'warning';
    return 'danger';
  }

  statusLabel(s: string): string { return s === 'at_risk' ? 'At Risk' : s === 'new' ? 'New' : 'Active'; }
  statusTone(s: string): string { return s === 'at_risk' ? 'danger' : s === 'new' ? 'secondary' : 'success'; }
}
