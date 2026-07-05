import {Component, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

interface Band { grade: string; min: number; }

/**
 * School settings — grading policy (pass mark + grade bands) and safeguarding
 * configuration (designated lead + policy note). Admin-only edit; the backend
 * enforces the same.
 */
@Component({
  selector: 'app-school-settings',
  standalone: true,
  imports: [Icon, PageHeader, FormsModule],
  templateUrl: './school-settings.html',
  styleUrl: './school-settings.css',
})
export class SchoolSettings {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  saving = signal(false);
  passMark = signal(50);
  bands = signal<Band[]>([]);
  safeguarding = signal({lead_name: '', lead_email: '', lead_phone: '', policy_note: ''});

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/school/settings').subscribe({
      next: (s) => {
        this.passMark.set(s?.grading?.pass_mark ?? 50);
        this.bands.set(s?.grading?.bands ?? []);
        this.safeguarding.set({...this.safeguarding(), ...(s?.safeguarding ?? {})});
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load settings'); },
    });
  }

  setBand(i: number, patch: Partial<Band>): void {
    this.bands.set(this.bands().map((b, idx) => idx === i ? {...b, ...patch} : b));
  }
  addBand(): void { this.bands.set([...this.bands(), {grade: '', min: 0}]); }
  removeBand(i: number): void { this.bands.set(this.bands().filter((_, idx) => idx !== i)); }

  setSafe(patch: Partial<{lead_name: string; lead_email: string; lead_phone: string; policy_note: string}>): void {
    this.safeguarding.set({...this.safeguarding(), ...patch});
  }

  save(): void {
    const bands = this.bands().filter(b => b.grade.trim());
    if (!bands.length) { this.toast.error('Add at least one grade band'); return; }
    this.saving.set(true);
    this.api.put('/backend/school/settings', {
      grading: {pass_mark: this.passMark(), bands},
      safeguarding: this.safeguarding(),
    }).subscribe({
      next: (s: any) => {
        this.bands.set(s?.grading?.bands ?? bands);
        this.passMark.set(s?.grading?.pass_mark ?? this.passMark());
        this.toast.success('Settings saved');
        this.saving.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Save failed'); this.saving.set(false); },
    });
  }
}
