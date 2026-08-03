import {Component, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../common/service/api.service';
import {Icon} from '../../../../common/icon/icon';

interface FeatureFlag { key: string; label: string; hint: string; }

@Component({
  selector: 'app-super-admin-system-settings',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule],
  templateUrl: './system-settings.html',
  styleUrl: './system-settings.css',
})
export class SuperAdminSystemSettings {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  saving = signal(false);
  /** Full settings shape (server always returns every section merged over defaults). */
  model: any = null;

  readonly featureFlags: FeatureFlag[] = [
    {key: 'live_classes', label: 'Live classes', hint: 'Scheduled live sessions and the virtual classroom'},
    {key: 'portfolio', label: 'Portfolio', hint: 'Competency-track evidence and task portfolio'},
    {key: 'messaging', label: 'Messaging', hint: '1:1 threaded messages between users'},
    {key: 'analytics', label: 'Analytics', hint: 'Progress analytics and reporting surfaces'},
    {key: 'ai_grading', label: 'AI grading', hint: 'Assisted marking suggestions (beta)'},
    {key: 'parent_portal', label: 'Parent portal', hint: 'Guardian accounts and the parent dashboard'},
  ];

  readonly locales = [{v: 'en', label: 'English'}, {v: 'fr', label: 'Français'}, {v: 'ar', label: 'العربية'}];

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/platform/settings').subscribe({
      next: (res) => { this.model = res; this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load system settings'); },
    });
  }

  save(): void {
    if (!this.model) return;
    this.saving.set(true);
    this.api.put<any>('/backend/platform/settings', this.model).subscribe({
      next: (res) => { this.model = res; this.saving.set(false); this.toast.success('System settings saved'); },
      error: (e) => { this.saving.set(false); this.toast.error(e?.error?.error || 'Could not save settings'); },
    });
  }

  /** Count of enabled feature flags, for the section summary. */
  enabledFlags(): number {
    const f = this.model?.feature_flags ?? {};
    return this.featureFlags.filter(x => f[x.key]).length;
  }
}
