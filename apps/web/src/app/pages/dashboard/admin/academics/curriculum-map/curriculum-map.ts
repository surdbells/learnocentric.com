import {Component, computed, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, StatRing} from '../../../../../common/ui';

@Component({
  selector: 'app-curriculum-map',
  standalone: true,
  imports: [PageHeader, Icon, FormsModule, KpiStrip, StatRing],
  templateUrl: './curriculum-map.html',
  styleUrl: './curriculum-map.css',
})
export class CurriculumMap {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  classes = signal<any[]>([]);
  terms = signal<any[]>([]);
  classId = signal<number | null>(null);
  termId = signal<number | null>(null);

  loading = signal(true);
  data = signal<any | null>(null);

  readonly stats = computed<any>(() => this.data()?.stats ?? null);
  readonly subjects = computed<any[]>(() => this.data()?.subjects ?? []);

  readonly kpis = computed<KpiItem[]>(() => {
    const s = this.stats();
    if (!s) return [];
    return [
      {label: 'Subjects', value: s.subjects, icon: 'subject', tone: 'primary'},
      {label: 'Topics', value: s.topics, icon: 'menu_book', tone: 'info'},
      {label: 'Packs published', value: s.packs_published, icon: 'layers', tone: 'success'},
      {label: 'Portfolio tasks', value: s.portfolio_tasks, icon: 'folder_special', tone: 'warning'},
    ];
  });

  readonly coverageTone = computed<'success' | 'warning' | 'danger'>(() => {
    const p = this.stats()?.coverage_pct ?? 0;
    return p >= 80 ? 'success' : p >= 40 ? 'warning' : 'danger';
  });

  constructor() {
    this.api.get<any>('/backend/school/classes').subscribe({next: (r) => this.classes.set(this.arr(r))});
    this.api.get<any>('/backend/school/terms').subscribe({next: (r) => this.terms.set(this.arr(r)), error: () => this.terms.set([])});
    this.load();
  }

  private arr(r: any): any[] { return Array.isArray(r) ? r : (r?.data ?? []); }

  load(): void {
    this.loading.set(true);
    const parts: string[] = [];
    if (this.classId()) parts.push(`class_id=${this.classId()}`);
    if (this.termId()) parts.push(`term_id=${this.termId()}`);
    const qs = parts.length ? '?' + parts.join('&') : '';
    this.api.get<any>(`/backend/curriculum/map${qs}`).subscribe({
      next: (r) => { this.data.set(r); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the curriculum map'); },
    });
  }

  /** Icon + tone for a pack/assessment cell. */
  cellIcon(status: string): string {
    if (status === 'published') return 'check_circle';
    if (status === 'none') return 'circle';
    return 'schedule'; // draft / review / approved, in progress
  }
  cellTone(status: string): string {
    if (status === 'published') return 'success';
    if (status === 'none') return 'secondary';
    return 'warning';
  }
  cellLabel(status: string): string {
    if (status === 'published') return 'Published';
    if (status === 'none') return 'Missing';
    return this.titleCase(status);
  }

  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
