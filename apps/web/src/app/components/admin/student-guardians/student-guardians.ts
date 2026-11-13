import {Component, effect, inject, input, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {Icon} from '../../../common/icon/icon';

interface GuardianRow { id: number; guardian_id: number; name: string; email: string; phone: string | null; relationship: string; }

/**
 * A learner's parents/guardians (admin). Link an existing parent account, or
 * create one with a temporary password, so the parent portal shows this child.
 * Safe to place inside another form: it never submits the host form.
 */
@Component({
  selector: 'app-student-guardians',
  standalone: true,
  imports: [FormsModule, Icon],
  templateUrl: './student-guardians.html',
})
export class StudentGuardians {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  readonly studentId = input<number | string | null | undefined>(null);

  readonly relationships = ['parent', 'mother', 'father', 'guardian', 'sibling', 'relative', 'other'];

  loading = signal(false);
  busy = signal(false);
  links = signal<GuardianRow[]>([]);
  parents = signal<{id: number; name: string; email: string}[]>([]);

  adding = signal(false);
  mode = signal<'existing' | 'new'>('existing');
  pickId = signal('');
  relationship = signal('parent');
  email = signal('');
  firstName = signal('');
  lastName = signal('');
  phone = signal('');
  password = signal('');

  constructor() {
    effect(() => {
      const id = Number(this.studentId() ?? 0);
      this.adding.set(false);
      this.links.set([]);
      if (id > 0) this.load(id);
    });
  }

  private load(id: number): void {
    this.loading.set(true);
    this.api.get<{data: GuardianRow[]; recorded: any}>(`/backend/school/guardians?student_id=${id}`).subscribe({
      next: (r) => {
        this.links.set(r?.data ?? []);
        // Pre-fill from what was recorded at admission.
        const rec = r?.recorded ?? {};
        const [first, ...rest] = String(rec.name ?? '').trim().split(/\s+/);
        this.email.set(rec.email ?? ''); this.firstName.set(first ?? ''); this.lastName.set(rest.join(' '));
        this.phone.set(rec.phone ?? '');
        if (this.relationships.includes(String(rec.relationship ?? '').toLowerCase())) this.relationship.set(String(rec.relationship).toLowerCase());
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  openAdd(): void {
    this.adding.set(true);
    this.pickId.set(''); this.password.set('');
    this.api.get<any>('/backend/school/guardians/parents').subscribe({
      next: (r) => {
        const linked = new Set(this.links().map(l => l.guardian_id));
        const list = (r?.data ?? []).filter((p: any) => !linked.has(p.id));
        this.parents.set(list);
        this.mode.set(list.length ? 'existing' : 'new');
      },
    });
  }

  link(): void {
    const student_id = Number(this.studentId());
    const body: any = {student_id, relationship: this.relationship()};
    if (this.mode() === 'existing') {
      if (!this.pickId()) { this.toast.error('Choose a parent account'); return; }
      body.guardian_id = Number(this.pickId());
    } else {
      if (!this.email().trim() || !this.firstName().trim() || !this.lastName().trim()) { this.toast.error('Enter the guardian\'s name and email'); return; }
      Object.assign(body, {email: this.email().trim(), firstName: this.firstName().trim(), lastName: this.lastName().trim(), phone: this.phone().trim() || null, password: this.password()});
    }
    this.busy.set(true);
    this.api.post<any>('/backend/school/guardians', body).subscribe({
      next: (r) => {
        this.busy.set(false); this.adding.set(false);
        this.toast.success(r?.account_created ? `Parent account created for ${r.name} and linked` : `${r?.name ?? 'Guardian'} linked`);
        this.load(student_id);
      },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'Could not link the guardian'); },
    });
  }

  unlink(row: GuardianRow): void {
    this.api.delete<any>(`/backend/school/guardians?id=${row.id}`, {confirm: `Unlink ${row.name}? Their parent account stays, but they'll no longer see this learner.`}).subscribe({
      next: () => { this.toast.success('Guardian unlinked'); this.load(Number(this.studentId())); },
      error: (e) => this.toast.error(e?.error?.error || 'Could not unlink'),
    });
  }
}
