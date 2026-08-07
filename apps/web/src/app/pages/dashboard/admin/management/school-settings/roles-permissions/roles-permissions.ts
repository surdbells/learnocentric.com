import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoModal} from '../../../../../../components/learno-modal/learno-modal';
import {Icon} from '../../../../../../common/icon/icon';
import {ApiService} from '../../../../../../common/service/api.service';

declare const bootstrap: any;

const ACTIONS = ['view', 'create', 'edit', 'approve', 'export', 'delete'] as const;
type Matrix = Record<string, Record<string, boolean>>;

/**
 * School Roles & Permissions (design: Settings & Permission_SA) — the roles
 * table with a permission matrix. System roles are read-only (shared across
 * institutions); custom institution roles can be created, edited, assigned and
 * deleted. Backed by /school/roles (real RolePermission RBAC).
 */
@Component({
  selector: 'app-roles-permissions',
  standalone: true,
  imports: [Icon, FormsModule, LearnoModal],
  templateUrl: './roles-permissions.html',
  styleUrl: './roles-permissions.css',
})
export class RolesPermissions {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  readonly actions = ACTIONS;
  loading = signal(true);
  roles = signal<any[]>([]);
  modules = signal<any[]>([]);
  stats = signal<any>({});
  busy = signal(false);

  // editor
  editing = signal<any | null>(null);       // role being edited (null = create)
  formName = signal('');
  formDesc = signal('');
  matrix = signal<Matrix>({});
  readOnly = signal(false);

  // assignment
  assignRole = signal<any | null>(null);
  assignable = signal<any[]>([]);
  assignUserId = signal<string>('');

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/school/roles').subscribe({
      next: (res) => {
        this.roles.set(res?.data ?? []);
        this.modules.set(res?.permissions ?? []);
        this.stats.set(res?.stats ?? {});
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load roles'); },
    });
  }

  readonly statCards = computed(() => {
    const s = this.stats();
    return [
      {label: 'Total Roles', value: s.total_roles ?? 0, icon: 'groups', tone: 'primary'},
      {label: 'Custom Roles', value: s.custom_roles ?? 0, icon: 'badge', tone: 'success'},
      {label: 'Permission Modules', value: s.permission_modules ?? 0, icon: 'tune', tone: 'info'},
      {label: 'Elevated Users', value: s.elevated_users ?? 0, icon: 'admin_panel_settings', tone: 'warning'},
    ];
  });

  private blankMatrix(): Matrix {
    const m: Matrix = {};
    for (const mod of this.modules()) m[mod.code] = {};
    return m;
  }

  openCreate(): void {
    this.editing.set(null);
    this.readOnly.set(false);
    this.formName.set('');
    this.formDesc.set('');
    this.matrix.set(this.blankMatrix());
    this.open('role_editor');
  }

  openEdit(role: any, readOnly = false): void {
    this.editing.set(role);
    this.readOnly.set(readOnly || !role.editable);
    this.formName.set(role.name);
    this.formDesc.set(role.description ?? '');
    const m = this.blankMatrix();
    for (const [code, g] of Object.entries<any>(role.grants ?? {})) {
      m[code] = {view: g.can_view, create: g.can_create, edit: g.can_edit, approve: g.can_approve, export: g.can_export, delete: g.can_delete};
    }
    this.matrix.set(m);
    this.open('role_editor');
  }

  toggle(code: string, action: string): void {
    if (this.readOnly()) return;
    const m = {...this.matrix()};
    m[code] = {...m[code], [action]: !m[code]?.[action]};
    this.matrix.set(m);
  }

  isOn(code: string, action: string): boolean { return !!this.matrix()[code]?.[action]; }

  save(): void {
    if (!this.formName().trim()) { this.toast.error('Give the role a name'); return; }
    this.busy.set(true);
    const grants: any = {};
    for (const [code, acts] of Object.entries(this.matrix())) {
      if (Object.values(acts).some(Boolean)) grants[code] = acts;
    }
    const body = {name: this.formName().trim(), description: this.formDesc().trim(), grants};
    const req = this.editing()
      ? this.api.put<any>(`/backend/school/roles/${this.editing().id}`, body)
      : this.api.post<any>('/backend/school/roles', body);
    req.subscribe({
      next: () => { this.toast.success(this.editing() ? 'Role updated' : 'Role created'); this.busy.set(false); this.close('role_editor'); this.load(); },
      error: (e: any) => { this.toast.error(e?.error?.error || 'Could not save'); this.busy.set(false); },
    });
  }

  remove(role: any): void {
    this.api.delete<any>(`/backend/school/roles?id=${role.id}`, {confirm: `Delete the role “${role.name}”?`}).subscribe({
      next: () => { this.toast.success('Role deleted'); this.load(); },
      error: (e: any) => this.toast.error(e?.error?.error || 'Delete failed'),
    });
  }

  openAssign(role: any): void {
    this.assignRole.set(role);
    this.assignUserId.set('');
    this.api.get<any>('/backend/school/roles/assignable-users').subscribe({
      next: (res) => this.assignable.set(res?.data ?? []),
    });
    this.open('role_assign');
  }

  doAssign(): void {
    const role = this.assignRole();
    if (!role || !this.assignUserId()) { this.toast.error('Choose a staff member'); return; }
    this.busy.set(true);
    this.api.post<any>('/backend/school/roles/assign', {user_id: Number(this.assignUserId()), role_id: role.id}).subscribe({
      next: (r: any) => { this.toast.success(`Assigned to ${r.role}`); this.busy.set(false); this.close('role_assign'); this.load(); },
      error: (e: any) => { this.toast.error(e?.error?.error || 'Assign failed'); this.busy.set(false); },
    });
  }

  levelTone(level: string): string {
    if (/Full/.test(level)) return 'success';
    if (/High/.test(level)) return 'primary';
    if (/Moderate/.test(level)) return 'info';
    if (/Read/.test(level)) return 'secondary';
    if (/No Access/.test(level)) return 'danger';
    return 'warning';
  }

  private open(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }
  private close(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
  }
}
