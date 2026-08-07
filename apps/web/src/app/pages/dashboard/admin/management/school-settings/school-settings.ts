import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {forkJoin} from 'rxjs';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {RichEditor} from '../../../../../common/rich-editor/rich-editor';
import {FileUpload, UploadedFile} from '../../../../../common/file-upload/file-upload';
import {RolesPermissions} from './roles-permissions/roles-permissions';

interface Band { grade: string; min: number; }

/**
 * School Admin "Settings & Permissions" hub (design: Settings & Permission_SA).
 * KPI strip + tabs (General / Academic Policies / Privacy & Security) + a rail
 * (settings summary, attention needed, quick actions). Institution-level config
 * over /school/profile + the extended /school/settings blob; grading and
 * safeguarding editors from the prior page are preserved as tabs.
 */
@Component({
  selector: 'app-school-settings',
  standalone: true,
  imports: [RichEditor, Icon, PageHeader, FormsModule, RouterLink, FileUpload, RolesPermissions],
  templateUrl: './school-settings.html',
  styleUrl: './school-settings.css',
})
export class SchoolSettings {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  saving = signal(false);
  tab = signal<'general' | 'roles' | 'academic' | 'privacy'>('general');

  // institution profile
  profile = signal<any>({});
  // extended settings blob
  settings = signal<any>({});
  // headline counts (real, from dashboard)
  counts = signal<{students: number; teachers: number; classes: number; subjects: number}>({students: 0, teachers: 0, classes: 0, subjects: 0});

  // grading (academic policies tab)
  passMark = signal(50);
  bands = signal<Band[]>([]);
  // safeguarding (privacy tab)
  safeguarding = signal({lead_name: '', lead_email: '', lead_phone: '', policy_note: ''});

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    forkJoin({
      profile: this.api.get<any>('/backend/school/profile'),
      settings: this.api.get<any>('/backend/school/settings'),
      dash: this.api.get<any>('/backend/dashboard/admin'),
    }).subscribe({
      next: ({profile, settings, dash}) => {
        this.profile.set(profile ?? {});
        this.settings.set(settings ?? {});
        this.passMark.set(settings?.grading?.pass_mark ?? 50);
        this.bands.set(settings?.grading?.bands ?? []);
        this.safeguarding.set({...this.safeguarding(), ...(settings?.safeguarding ?? {})});
        const k = dash?.stats ?? {};
        this.counts.set({
          students: k.students ?? 0,
          teachers: k.teachers ?? 0,
          classes: k.classes ?? 0,
          subjects: k.subjects ?? 0,
        });
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load settings'); },
    });
  }

  // ---- section value helpers (extended settings) ----
  sec(section: string, key: string): any { return this.settings()?.[section]?.[key]; }
  setSec(section: string, key: string, value: any): void {
    const cur = {...(this.settings() ?? {})};
    cur[section] = {...(cur[section] ?? {}), [key]: value};
    this.settings.set(cur);
  }
  toggleSec(section: string, key: string): void { this.setSec(section, key, !this.sec(section, key)); }

  // ---- profile helpers ----
  prof(key: string): any { return this.profile()?.[key]; }
  setProf(key: string, value: any): void { this.profile.set({...(this.profile() ?? {}), [key]: value}); }
  onLogoUploaded(f: UploadedFile): void { this.setProf('logo_url', f.url); }
  onLogoCleared(): void { this.setProf('logo_url', ''); }

  contact(key: string): any { return this.profile()?.admin_contact?.[key]; }
  setContact(key: string, value: any): void {
    const c = {...(this.profile()?.admin_contact ?? {}), [key]: value};
    this.profile.set({...(this.profile() ?? {}), admin_contact: c});
  }

  // ---- grading ----
  setBand(i: number, patch: Partial<Band>): void { this.bands.set(this.bands().map((b, idx) => idx === i ? {...b, ...patch} : b)); }
  addBand(): void { this.bands.set([...this.bands(), {grade: '', min: 0}]); }
  removeBand(i: number): void { this.bands.set(this.bands().filter((_, idx) => idx !== i)); }

  // ---- safeguarding ----
  setSafe(patch: Partial<{lead_name: string; lead_email: string; lead_phone: string; policy_note: string}>): void {
    this.safeguarding.set({...this.safeguarding(), ...patch});
  }

  // ---- rail: completion summary + attention ----
  summary = computed(() => {
    const p = this.profile(), s = this.settings();
    const has = (v: any) => v !== null && v !== undefined && String(v).trim() !== '';
    return [
      {label: 'School Profile', done: has(p?.name) && has(p?.admin_contact?.email)},
      {label: 'Academic Defaults', done: has(s?.academic?.naming_convention)},
      {label: 'Localization', done: has(s?.localization?.timezone)},
      {label: 'Branding', done: has(s?.branding?.portal_name) || has(p?.brand_color)},
      {label: 'Notification Defaults', done: true, configured: true},
      {label: 'Grading Policy', done: (this.bands()?.length ?? 0) > 0, configured: true},
      {label: 'Safeguarding', done: has(this.safeguarding().lead_name), configured: true},
    ];
  });

  attention = computed(() => {
    const p = this.profile();
    const items: {label: string; level: string}[] = [];
    if (!p?.logo_url) items.push({label: 'Upload school logo', level: 'Required'});
    if (!this.safeguarding().lead_name?.trim()) items.push({label: 'Set safeguarding lead', level: 'Review'});
    if (!this.bands()?.length) items.push({label: 'Configure grading bands', level: 'Review'});
    if (!this.sec('branding', 'portal_name')) items.push({label: 'Set portal name', level: 'Optional'});
    return items;
  });

  completionPct = computed(() => {
    const s = this.summary();
    return Math.round((s.filter(x => x.done).length / s.length) * 100);
  });

  save(): void {
    this.saving.set(true);
    const s = this.settings();
    const bands = this.bands().filter(b => b.grade.trim());
    const profilePayload = {
      name: this.prof('name'),
      type: this.prof('type'),
      address: this.prof('address'),
      logo_url: this.prof('logo_url'),
      brand_color: this.prof('brand_color'),
      admin_contact: this.profile()?.admin_contact ?? {},
    };
    const settingsPayload = {
      grading: {pass_mark: this.passMark(), bands},
      safeguarding: this.safeguarding(),
      academic: s?.academic ?? {},
      localization: s?.localization ?? {},
      branding: s?.branding ?? {},
      notifications: s?.notifications ?? {},
      data: s?.data ?? {},
    };
    forkJoin({
      profile: this.api.put<any>('/backend/school/profile', profilePayload),
      settings: this.api.put<any>('/backend/school/settings', settingsPayload),
    }).subscribe({
      next: ({profile, settings}) => {
        this.profile.set(profile ?? this.profile());
        this.settings.set(settings ?? this.settings());
        this.bands.set(settings?.grading?.bands ?? bands);
        this.passMark.set(settings?.grading?.pass_mark ?? this.passMark());
        this.saving.set(false);
        this.toast.success('Settings saved');
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Save failed'); this.saving.set(false); },
    });
  }
}
