import {Component, computed, inject, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../common/auth/auth.service';
import {ApiService} from '../../../../common/service/api.service';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../common/icon/icon';
import {KpiItem, KpiStrip, StatRing} from '../../../../common/ui';

interface Step { key: string; label: string; hint: string; done: boolean; link: string; icon: string; }

/**
 * School Setup (school admin), a consolidated onboarding hub. Reads the config
 * that already exists (roster counts, grading policy, safeguarding lead) and
 * shows a completion ring + a checklist that links each step to where it's set.
 * No new backend: derived from /dashboard/admin + /school/settings.
 */
@Component({
  selector: 'app-school-setup',
  standalone: true,
  imports: [RouterLink, PageHeader, Icon, KpiStrip, StatRing],
  templateUrl: './school-setup.html',
  styleUrl: './school-setup.css',
})
export class SchoolSetup {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);

  loading = signal(true);
  stats = signal<any>(null);
  settings = signal<any>(null);
  root = signal('/admin');

  readonly checklist = computed<Step[]>(() => {
    const s = this.stats(); const cfg = this.settings();
    if (!s || !cfg) return [];
    const r = this.root(), st = s.stats ?? {};
    const grading = cfg.grading ?? {}; const safe = cfg.safeguarding ?? {};
    return [
      {key: 'learners', label: 'Enrol learners', hint: 'Add your students', done: (st.students ?? 0) > 0, link: `${r}/students`, icon: 'group'},
      {key: 'teachers', label: 'Add teaching staff', hint: 'Onboard teachers', done: (st.teachers ?? 0) > 0, link: `${r}/teachers`, icon: 'supervisor_account'},
      {key: 'subjects', label: 'Choose subjects offered', hint: 'Adopt from the catalogue', done: (st.subjects ?? 0) > 0, link: `${r}/academics/subjects`, icon: 'subject'},
      {key: 'classes', label: 'Create classes', hint: 'Set up class arms', done: (st.classes ?? 0) > 0, link: `${r}/academics/classes`, icon: 'meeting_room'},
      {key: 'grading', label: 'Set grading policy', hint: 'Pass mark & grade bands', done: (grading.bands ?? []).length > 0, link: `${r}/management/settings`, icon: 'grading'},
      {key: 'safeguarding', label: 'Assign a safeguarding lead', hint: 'Name a designated lead', done: !!(safe.lead_name || safe.lead_email), link: `${r}/management/settings`, icon: 'shield'},
    ];
  });

  readonly completePct = computed<number>(() => {
    const c = this.checklist();
    return c.length ? Math.round((c.filter(x => x.done).length / c.length) * 100) : 0;
  });

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.stats()?.stats ?? {};
    const c = this.checklist();
    return [
      {label: 'Steps complete', value: `${c.filter(x => x.done).length}/${c.length}`, icon: 'done_all', tone: 'success'},
      {label: 'Learners', value: s.students ?? 0, icon: 'group', tone: 'primary'},
      {label: 'Teachers', value: s.teachers ?? 0, icon: 'supervisor_account', tone: 'warning'},
      {label: 'Classes', value: s.classes ?? 0, icon: 'meeting_room', tone: 'info'},
    ];
  });

  constructor() {
    this.root.set(this.auth.getAuthSession()?.user?.role === 'tutor_admin' ? '/academy' : '/admin');
    this.load();
  }

  load(): void {
    this.loading.set(true);
    let pending = 2;
    const done = () => { if (--pending === 0) this.loading.set(false); };
    this.api.get<any>('/backend/dashboard/admin').subscribe({next: r => { this.stats.set(r); done(); }, error: done});
    this.api.get<any>('/backend/school/settings').subscribe({next: r => { this.settings.set(r); done(); }, error: done});
  }

  ringTone(): 'success' | 'warning' | 'danger' {
    const p = this.completePct();
    return p >= 100 ? 'success' : p >= 50 ? 'warning' : 'danger';
  }
}
