import {Component, computed, inject, signal} from '@angular/core';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../../common/ui';

/**
 * Subjects are defined in the platform catalogue by the SaaS admin; a school
 * chooses which of them it offers. This page lists the catalogue and lets an
 * admin adopt or remove a subject for their school.
 */
@Component({
  selector: 'app-subjects',
  standalone: true,
  imports: [RichText, PageHeader, Icon, KpiStrip, TabBar],
  templateUrl: './subjects.html',
  styleUrl: './subjects.css',
})
export class Subjects {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  busy = signal<number | null>(null);
  catalog = signal<any[]>([]);
  listTab = signal<string>('all');

  readonly kpis = computed<KpiItem[]>(() => {
    const c = this.catalog();
    const adopted = c.filter(x => x.adopted).length;
    // 'Catalogue subjects' (total across the platform) is a Super-Admin concern —
    // the school only cares what it offers and can add (PDF review A5).
    return [
      {label: 'Offered by school', value: adopted, icon: 'check_circle', tone: 'success'},
      {label: 'Available to add', value: c.length - adopted, icon: 'add', tone: 'info'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const c = this.catalog();
    const adopted = c.filter(x => x.adopted).length;
    return [
      {key: 'all', label: 'All', count: c.length},
      {key: 'offered', label: 'Offered', count: adopted},
      {key: 'available', label: 'Available', count: c.length - adopted},
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.listTab(), c = this.catalog();
    if (t === 'offered') return c.filter(x => x.adopted);
    if (t === 'available') return c.filter(x => !x.adopted);
    return c;
  });

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/school/subjects/available').subscribe({
      next: (res) => { this.catalog.set(Array.isArray(res) ? res : (res?.data ?? [])); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load subjects'); },
    });
  }

  toggle(item: any): void {
    this.busy.set(item.id);
    if (item.adopted) {
      this.api.delete(`/backend/school/subjects?id=${item.subject_id}`, {confirm: false}).subscribe({
        next: () => { this.toast.success(`${item.name} removed from your school`); this.after(); },
        error: (e) => { this.toast.error(e?.error?.error || 'Could not remove — it may have topics or classes attached'); this.busy.set(null); },
      });
    } else {
      this.api.post('/backend/school/subjects/adopt', {catalog_subject_id: item.id}).subscribe({
        next: () => { this.toast.success(`${item.name} added to your school`); this.after(); },
        error: (e) => { this.toast.error(e?.error?.error || 'Could not add the subject'); this.busy.set(null); },
      });
    }
  }

  private after(): void { this.busy.set(null); this.load(); }
}
