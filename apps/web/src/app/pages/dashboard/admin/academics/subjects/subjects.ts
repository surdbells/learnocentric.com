import {Component, inject, signal} from '@angular/core';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {ApiService} from '../../../../../common/service/api.service';
import {RichText} from '../../../../../common/rich-editor/rich-text';

/**
 * Subjects are defined in the platform catalogue by the SaaS admin; a school
 * chooses which of them it offers. This page lists the catalogue and lets an
 * admin adopt or remove a subject for their school.
 */
@Component({
  selector: 'app-subjects',
  standalone: true,
  imports: [RichText, PageHeader, Icon],
  templateUrl: './subjects.html',
  styleUrl: './subjects.css',
})
export class Subjects {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  busy = signal<number | null>(null);
  catalog = signal<any[]>([]);

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
