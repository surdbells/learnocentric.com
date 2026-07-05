import {Component, inject, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../common/learno-button/learno-button';
import {ApiService} from '../../../../common/service/api.service';

declare const bootstrap: any;

/** Super-admin management of the platform subject catalogue. */
@Component({
  selector: 'app-catalog-subjects',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, ReactiveFormsModule],
  templateUrl: './catalog-subjects.html',
  styleUrl: './catalog-subjects.css',
})
export class CatalogSubjects {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  busy = signal(false);
  subjects = signal<any[]>([]);
  editing = signal<any | null>(null);

  form = new FormGroup({
    name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    code: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    curriculum: new FormControl('NERDC', {nonNullable: true}),
    description: new FormControl(''),
    isActive: new FormControl(true, {nonNullable: true}),
  });

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/catalog/subjects?all=1').subscribe({
      next: (r) => { this.subjects.set(Array.isArray(r) ? r : (r?.data ?? [])); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the catalogue'); },
    });
  }

  openCreate(): void {
    this.editing.set(null);
    this.form.reset({curriculum: 'NERDC', isActive: true});
    this.form.get('code')!.enable();
    this.open();
  }

  openEdit(s: any): void {
    this.editing.set(s);
    this.form.reset({curriculum: s.curriculum ?? 'NERDC', isActive: s.is_active});
    this.form.patchValue({name: s.name, code: s.code, description: s.description ?? ''});
    this.form.get('code')!.disable();
    this.open();
  }

  save(): void {
    if (this.form.get('name')!.invalid || (!this.editing() && this.form.get('code')!.invalid)) {
      this.toast.error('A name and code are required');
      return;
    }
    const v = this.form.getRawValue();
    const body: any = {name: v.name, code: v.code, curriculum: v.curriculum, description: v.description, is_active: v.isActive};
    this.busy.set(true);
    const req = this.editing()
      ? this.api.put('/backend/catalog/subjects', {...body, id: this.editing().id})
      : this.api.post('/backend/catalog/subjects', body);
    req.subscribe({
      next: () => { this.toast.success(this.editing() ? 'Subject updated' : 'Subject added'); this.busy.set(false); this.close(); this.load(); },
      error: (e) => { this.toast.error(e?.error?.error || 'Save failed'); this.busy.set(false); },
    });
  }

  retire(s: any): void {
    if (!confirm(`Retire ${s.name}? Schools already using it keep it, but it won't be offered to new ones.`)) return;
    this.api.delete(`/backend/catalog/subjects?id=${s.id}`, {confirm: false}).subscribe({
      next: () => { this.toast.success('Subject retired'); this.load(); },
      error: () => this.toast.error('Could not retire'),
    });
  }

  private open(): void {
    const el = document.getElementById('catalog_subject_form');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }
  private close(): void {
    const el = document.getElementById('catalog_subject_form');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
  }
}
