import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, StatRing, StatusBadge} from '../../../../../common/ui';
import {Tone} from '../../../../../common/ui/ui-types';

const STATUS_TONE: Record<string, Tone> = {draft: 'secondary', review: 'info', approved: 'primary', published: 'success', archived: 'secondary'};

@Component({
  selector: 'app-scheme-detail',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, KpiStrip, StatRing, StatusBadge],
  templateUrl: './scheme-detail.html',
  styleUrl: './scheme-detail.css',
})
export class SchemeDetail {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  readonly statusTones = STATUS_TONE;
  readonly materialKeys = [
    {key: 'teacher_guide', label: 'Teacher guide', icon: 'description'},
    {key: 'learner_note', label: 'Learner note', icon: 'menu_book'},
    {key: 'video', label: 'Video', icon: 'video'},
    {key: 'worked_examples', label: 'Worked examples', icon: 'edit_note'},
  ];

  classes = signal<any[]>([]);
  subjects = signal<any[]>([]);
  terms = signal<any[]>([]);

  classId = signal<number | null>(null);
  subjectId = signal<number | null>(null);
  termId = signal<number | null>(null);

  loading = signal(false);
  detail = signal<any | null>(null);

  readonly stats = computed<any>(() => this.detail()?.stats ?? null);
  readonly weeks = computed<any[]>(() => this.detail()?.weeks ?? []);
  readonly header = computed<any>(() => this.detail()?.header ?? null);

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.stats();
    if (!s) return [];
    return [
      {label: 'Weeks planned', value: s.total_weeks, icon: 'calendar_month', tone: 'primary'},
      {label: 'Topics assigned', value: `${s.weeks_with_topic}/${s.total_weeks}`, sublabel: s.topic_pct + '%', icon: 'subject', tone: s.topic_pct === 100 ? 'success' : 'warning'},
      {label: 'Packs ready', value: `${s.packs_ready}/${s.total_weeks}`, icon: 'layers', tone: s.packs_ready > 0 ? 'info' : 'secondary'},
      {label: 'Published weeks', value: s.by_status?.published ?? 0, icon: 'verified', tone: 'success'},
    ];
  });

  readonly coverageTone = computed<'success' | 'warning' | 'danger'>(() => {
    const p = this.stats()?.coverage_pct ?? 0;
    return p >= 80 ? 'success' : p >= 40 ? 'warning' : 'danger';
  });

  constructor() {
    this.api.get<any>('/backend/school/classes').subscribe({next: (r) => this.classes.set(this.arr(r))});
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(this.arr(r))});
    this.api.get<any>('/backend/school/terms').subscribe({next: (r) => this.terms.set(this.arr(r)), error: () => this.terms.set([])});
  }

  private arr(r: any): any[] { return Array.isArray(r) ? r : (r?.data ?? []); }

  load(): void {
    const c = this.classId(), s = this.subjectId();
    if (!c || !s) { this.detail.set(null); return; }
    this.loading.set(true);
    let url = `/backend/school/scheme-of-work/detail?class_id=${c}&subject_id=${s}`;
    if (this.termId()) url += `&term_id=${this.termId()}`;
    this.api.get<any>(url).subscribe({
      next: (r) => { this.detail.set(r); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the scheme'); },
    });
  }

  materialsReady(week: any): number {
    if (!week.pack) return 0;
    return this.materialKeys.filter(m => week.pack.materials[m.key]).length;
  }

  statusTone(s: string): string { return STATUS_TONE[s] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
