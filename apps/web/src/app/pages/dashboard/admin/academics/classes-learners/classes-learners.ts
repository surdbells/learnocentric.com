import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';
import {KpiStrip, KpiItem} from '../../../../../common/ui';

/**
 * School-Admin "Classes & Learners" unified hub (design: Classes & Learners_SA)
 * — KPI strip, a class list, and the selected class's learner roster with real
 * average scores, risk status and intervention flags, plus a class-overview
 * rail. Backed by /school/classes-learners + /school/classes/{id}/roster.
 * Add/Bulk-import/Enrolment reuse the existing dedicated pages.
 */
@Component({
  selector: 'app-classes-learners',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, RouterLink, KpiStrip],
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
