import {Component, inject, OnInit, signal, PLATFORM_ID} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../common/icon/icon';
import {ApiService} from '../../../../common/service/api.service';
import {Preferences} from '../../../../common/service/preferences';
import {ToastrService} from 'ngx-toastr';

interface MenuItem { key: string; label: string; sub: string; icon: string; }

/**
 * Learner Settings hub, menu + section cards (design: Settings_LD).
 * Reads/writes the shared /auth/settings preferences blob; Appearance also
 * drives the live app theme via Preferences. No fabricated data: the storage
 * card reports real browser-cache usage.
 */
@Component({
  selector: 'app-student-settings',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, RouterLink],
  templateUrl: './settings.html',
  styleUrl: './settings.css',
})
export class StudentSettings implements OnInit {
  private api = inject(ApiService);
  private prefs = inject(Preferences);
  private toast = inject(ToastrService);
  private platformId = inject(PLATFORM_ID);

  loading = signal(true);
  saving = signal(false);
  profile = signal<any>(null);
  security = signal<any>(null);
  prefsData = signal<any>(null);
  activeSection = signal('account');

  // password change
  pwCurrent = signal('');
  pwNew = signal('');
  pwConfirm = signal('');
  pwShow = signal(false);
  pwBusy = signal(false);

  readonly menu: MenuItem[] = [
    {key: 'account', label: 'Account Settings', sub: 'Profile, personal info, password', icon: 'account_circle'},
    {key: 'learning', label: 'Learning Preferences', sub: 'Subjects, goals, reminders', icon: 'tune'},
    {key: 'notifications', label: 'Notifications', sub: 'Email, push, SMS preferences', icon: 'notifications'},
    {key: 'privacy', label: 'Privacy & Security', sub: 'Privacy controls, data usage', icon: 'shield'},
    {key: 'appearance', label: 'Appearance', sub: 'Theme, text size, display', icon: 'palette'},
    {key: 'help', label: 'Help & Support', sub: 'FAQs, contact support', icon: 'help'},
  ];

  ngOnInit(): void {
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/auth/settings').subscribe({
      next: (res) => {
        this.profile.set(res?.profile ?? null);
        this.security.set(res?.security ?? null);
        this.prefsData.set(res?.preferences ?? {});
        this.loading.set(false);
      },
      error: () => { this.toast.error('Could not load your settings'); this.loading.set(false); },
    });
  }

  select(key: string): void {
    this.activeSection.set(key);
    if (!isPlatformBrowser(this.platformId)) return;
    document.getElementById('sec-' + key)?.scrollIntoView({behavior: 'smooth', block: 'start'});
  }

  /** Read a nested preference value with a fallback. */
  pref(section: string, key: string): any {
    return this.prefsData()?.[section]?.[key];
  }

  /** Optimistically patch one preference and persist the whole section. */
  private patch(section: string, key: string, value: any): void {
    const cur = {...(this.prefsData() ?? {})};
    cur[section] = {...(cur[section] ?? {}), [key]: value};
    this.prefsData.set(cur);
    this.save({[section]: {[key]: value}});
  }

  toggle(section: string, key: string): void {
    this.patch(section, key, !this.pref(section, key));
  }

  setValue(section: string, key: string, value: any): void {
    this.patch(section, key, value);
  }

  setTheme(theme: string): void {
    this.prefs.setTheme(theme); // live-apply app-wide
    this.patch('appearance', 'theme', theme);
  }

  setTextSize(size: string): void {
    this.applyTextSize(size);
    this.patch('appearance', 'text_size', size);
  }

  private applyTextSize(size: string): void {
    if (!isPlatformBrowser(this.platformId)) return;
    const map: Record<string, string> = {small: '15px', medium: '16px', large: '18px'};
    document.documentElement.style.fontSize = map[size] ?? '16px';
  }

  private save(patch: any): void {
    this.saving.set(true);
    this.api.put<any>('/backend/auth/settings', {preferences: patch}).subscribe({
      next: (res) => { if (res?.preferences) this.prefsData.set(res.preferences); this.saving.set(false); },
      error: () => { this.toast.error('Could not save that change'); this.saving.set(false); this.load(); },
    });
  }

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
