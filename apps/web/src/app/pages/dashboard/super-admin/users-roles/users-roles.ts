import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';
import {AvatarCell, KpiItem, KpiStrip, StatusBadge, TabBar, TabItem, Tone} from '../../../../common/ui';

/**
 * Users & Roles (super admin) — platform-wide user directory over
 * /backend/admin/users, with per-role KPI cards, role tabs, search and
 * pagination. Read-only view; the RBAC tables drive the role labels.
 */
@Component({
  selector: 'app-super-admin-users-roles',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, KpiStrip, TabBar, StatusBadge, AvatarCell],
  templateUrl: './users-roles.html',
  styleUrl: './users-roles.css',
})
export class SuperAdminUsersRoles {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  rows = signal<any[]>([]);
  stats = signal<any>({total: 0, suspended: 0, by_role: []});
  meta = signal<any>({total: 0, page: 1, per_page: 25});
  role = signal<string>('all');
  search = signal<string>('');
  page = signal<number>(1);

  readonly roleTone: Record<string, Tone> = {
    super_admin: 'danger', school_admin: 'primary', tutor_admin: 'primary',
    academic_lead: 'info', teacher: 'warning', student: 'success', parent: 'secondary',
  };
  readonly statusMap: Record<string, Tone> = {active: 'success', suspended: 'secondary'};

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.stats();
    const cnt = (r: string) => (s.by_role ?? []).find((x: any) => x.role === r)?.count ?? 0;
    return [
      {label: 'Total users', value: s.total ?? 0, icon: 'group', tone: 'primary'},
      {label: 'Students', value: cnt('student'), icon: 'local_library', tone: 'success'},
      {label: 'Teachers', value: cnt('teacher'), icon: 'supervisor_account', tone: 'warning'},
      {label: 'Suspended', value: s.suspended ?? 0, icon: 'cancel', tone: s.suspended ? 'danger' : 'secondary'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const roles = this.stats()?.by_role ?? [];
    return [
      {key: 'all', label: 'All', count: this.stats()?.total ?? 0},
      ...roles.map((r: any) => ({key: r.role, label: r.name, count: r.count})),
    ];
  });

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    const params: any = {page: this.page(), per_page: 25};
    if (this.role() !== 'all') params.role = this.role();
    if (this.search().trim()) params.q = this.search().trim();
    this.api.get<any>('/backend/admin/users', {params}).subscribe({
      next: (res) => {
        this.rows.set(res?.data ?? []);
        this.meta.set(res?.meta ?? this.meta());
        if (res?.stats) this.stats.set(res.stats);
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load the user directory'); },
    });
  }

  setRole(k: string): void { this.role.set(k); this.page.set(1); this.load(); }
  runSearch(): void { this.page.set(1); this.load(); }
  prev(): void { if (this.page() > 1) { this.page.set(this.page() - 1); this.load(); } }
  next(): void { if (this.page() * (this.meta().per_page || 25) < (this.meta().total || 0)) { this.page.set(this.page() + 1); this.load(); } }

  totalPages(): number { const m = this.meta(); return Math.max(1, Math.ceil((m.total || 0) / (m.per_page || 25))); }
  fullName(r: any): string { return `${r.first_name ?? ''} ${r.last_name ?? ''}`.trim() || r.email; }
  roleColor(code: string): Tone { return this.roleTone[code] ?? 'secondary'; }
}
