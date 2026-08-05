import {Component, inject, OnInit, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {DatePipe} from '@angular/common';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../common/icon/icon';
import {ApiService} from '../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';

/**
 * Teacher Settings hub — numbered sections + right rail (design: Settings_TD).
 * Edits accumulate in a working copy and persist on explicit Save (design has
 * Save Changes / Reset). Reads/writes the shared /auth/settings blob; the rail's
 * account snapshot (last login, session) is REAL from the settings payload.
 */
@Component({
  selector: 'app-teacher-settings',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, RouterLink, DatePipe],
  templateUrl: './settings.html',
  styleUrl: './settings.css',
})
export class TeacherSettings implements OnInit {
  private api = inject(ApiService);
  private toast = inject(ToastrService);

  loading = signal(true);
  saving = signal(false);
  dirty = signal(false);
  profile = signal<any>(null);
  security = signal<any>(null);
  prefs = signal<any>({});

  // password change
  pwCurrent = signal('');
  pwNew = signal('');
  pwConfirm = signal('');
  pwBusy = signal(false);

  ngOnInit(): void { this.load(); }

  private load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/auth/settings').subscribe({
      next: (res) => {
        this.profile.set(res?.profile ?? null);
        this.security.set(res?.security ?? null);
        this.prefs.set(structuredClone(res?.preferences ?? {}));
        this.dirty.set(false);
        this.loading.set(false);
      },
      error: () => { this.toast.error('Could not load your settings'); this.loading.set(false); },
    });
  }

  pref(section: string, key: string): any { return this.prefs()?.[section]?.[key]; }

  set(section: string, key: string, value: any): void {
    const cur = structuredClone(this.prefs() ?? {});
    cur[section] = {...(cur[section] ?? {}), [key]: value};
    this.prefs.set(cur);
    this.dirty.set(true);
  }

  toggle(section: string, key: string): void { this.set(section, key, !this.pref(section, key)); }

  save(): void {
    this.saving.set(true);
    this.api.put<any>('/backend/auth/settings', {preferences: this.prefs()}).subscribe({
      next: (res) => {
        if (res?.preferences) this.prefs.set(structuredClone(res.preferences));
        this.dirty.set(false);
        this.saving.set(false);
        this.toast.success('Settings saved');
      },
      error: () => { this.toast.error('Could not save settings'); this.saving.set(false); },
    });
  }

  reset(): void { this.load(); this.toast.info('Reverted to your saved settings'); }

  updatePassword(): void {
    if (!this.pwCurrent() || !this.pwNew()) { this.toast.error('Fill in all password fields'); return; }
    if (this.pwNew() !== this.pwConfirm()) { this.toast.error('New passwords do not match'); return; }
    this.pwBusy.set(true);
    this.api.post<any>('/backend/auth/password', {
      current_password: this.pwCurrent(),
      new_password: this.pwNew(),
      confirm_password: this.pwConfirm(),
    }).subscribe({
      next: () => {
        this.toast.success('Password updated');
        this.pwCurrent.set(''); this.pwNew.set(''); this.pwConfirm.set('');
        this.pwBusy.set(false);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not update password'); this.pwBusy.set(false); },
    });
  }
}
