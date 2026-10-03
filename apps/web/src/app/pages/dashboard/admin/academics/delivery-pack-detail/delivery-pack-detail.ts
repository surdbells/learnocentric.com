import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {StatRing, StatusBadge} from '../../../../../common/ui';
import {Tone} from '../../../../../common/ui/ui-types';

const STATUS_TONE: Record<string, Tone> = {draft: 'secondary', review: 'info', approved: 'primary', published: 'success', archived: 'secondary'};

@Component({
  selector: 'app-delivery-pack-detail',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, FormsModule, RichText, StatRing, StatusBadge],
  templateUrl: './delivery-pack-detail.html',
  styleUrl: './delivery-pack-detail.css',
})
export class DeliveryPackDetail {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  readonly statusTones = STATUS_TONE;
  readonly materialKeys = [
    {key: 'teacher_guide', label: 'Teacher guide', icon: 'description'},
    {key: 'learner_note', label: 'Learner note', icon: 'menu_book'},
    {key: 'video', label: 'Video', icon: 'video'},
    {key: 'worked_examples', label: 'Worked examples', icon: 'edit_note'},
    {key: 'parent_wording', label: 'Parent wording', icon: 'forum'},
  ];

  packs = signal<any[]>([]);
  selectedId = signal<number | null>(null);
  loading = signal(false);
  detail = signal<any | null>(null);

  readonly pack = computed<any>(() => this.detail()?.pack ?? null);
  readonly topic = computed<any>(() => this.detail()?.topic ?? null);
  readonly readiness = computed<any>(() => this.detail()?.readiness ?? null);
  readonly history = computed<any[]>(() => this.detail()?.history ?? []);
  readonly media = computed<any[]>(() => this.pack()?.media ?? []);
  readonly misconceptions = computed<any[]>(() => this.topic()?.misconceptions ?? []);

  readonly readinessTone = computed<'success' | 'warning' | 'danger'>(() => {
    const p = this.readiness()?.percent ?? 0;
    return p >= 80 ? 'success' : p >= 40 ? 'warning' : 'danger';
  });

  constructor() {
    this.api.get<any>('/backend/curriculum/delivery-packs?paginated=false').subscribe({
      next: (r) => this.packs.set(Array.isArray(r) ? r : (r?.data ?? [])),
      error: () => this.toast.error('Could not load delivery packs'),
    });
  }

  load(): void {
    const id = this.selectedId();
    if (!id) { this.detail.set(null); return; }
    this.loading.set(true);
    this.api.get<any>(`/backend/curriculum/delivery-packs/${id}`).subscribe({
      next: (r) => { this.detail.set(r); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the pack'); },
    });
  }

  materialOn(key: string): boolean { return !!this.readiness()?.materials?.[key]; }
  statusTone(s: string): string { return STATUS_TONE[s] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : ''; }
}
