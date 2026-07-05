import {Component, inject, signal} from '@angular/core';
import {DecimalPipe} from '@angular/common';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../components/learno-modal/learno-modal';
import {LearnoButton} from '../../../../common/learno-button/learno-button';
import {ApiService} from '../../../../common/service/api.service';

declare const bootstrap: any;

@Component({
  selector: 'app-super-admin-plans',
  standalone: true,
  imports: [PageHeader, LearnoModal, LearnoButton, ReactiveFormsModule, DecimalPipe],
  templateUrl: './plans.html',
  styleUrl: './plans.css',
})
export class SuperAdminPlans {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  busy = signal(false);
  plans = signal<any[]>([]);
  editing = signal<any | null>(null);

  form = new FormGroup({
    code: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    priceNaira: new FormControl<number | null>(null, {validators: [Validators.required]}),
    interval: new FormControl('termly', {nonNullable: true}),
    maxStudents: new FormControl<number | null>(null),
    maxTeachers: new FormControl<number | null>(null),
    features: new FormControl('', {nonNullable: true}),
    isActive: new FormControl(true, {nonNullable: true}),
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/billing/plans?all=1').subscribe({
      next: (r) => { this.plans.set(Array.isArray(r) ? r : (r?.data ?? [])); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load plans'); },
    });
  }

  openCreate(): void {
    this.editing.set(null);
    this.form.reset({interval: 'termly', isActive: true});
    this.form.get('code')!.enable();
    this.open();
  }

  openEdit(plan: any): void {
    this.editing.set(plan);
    this.form.reset({interval: plan.interval, isActive: plan.is_active});
    this.form.patchValue({
      code: plan.code,
      name: plan.name,
      priceNaira: plan.price_naira,
      maxStudents: plan.max_students,
      maxTeachers: plan.max_teachers,
      features: (plan.features ?? []).join('\n'),
    });
    this.form.get('code')!.disable(); // code is the immutable key
    this.open();
  }

  save(): void {
    if (this.form.get('name')!.invalid || this.form.get('priceNaira')!.invalid || (!this.editing() && this.form.get('code')!.invalid)) {
      this.toast.error('Code, name and price are required');
      return;
    }
    const v = this.form.getRawValue();
    const body: any = {
      code: v.code,
      name: v.name,
      price_kobo: Math.round((v.priceNaira ?? 0) * 100),
      interval: v.interval,
      max_students: v.maxStudents,
      max_teachers: v.maxTeachers,
      features: String(v.features || '').split('\n').map((f) => f.trim()).filter(Boolean),
      is_active: v.isActive,
    };
    this.busy.set(true);
    const req = this.editing()
      ? this.api.put('/backend/billing/plans', {...body, id: this.editing().id})
      : this.api.post('/backend/billing/plans', body);
    req.subscribe({
      next: () => {
        this.toast.success(this.editing() ? 'Plan updated' : 'Plan created');
        this.busy.set(false);
        this.close();
        this.load();
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Save failed'); this.busy.set(false); },
    });
  }

  deactivate(plan: any): void {
    if (!confirm(`Deactivate the ${plan.name} plan? Institutions already on it keep access.`)) return;
    this.api.delete(`/backend/billing/plans?id=${plan.id}`, {confirm: false}).subscribe({
      next: () => { this.toast.success('Plan deactivated'); this.load(); },
      error: () => this.toast.error('Could not deactivate'),
    });
  }

  private open(): void {
    const el = document.getElementById('plan_form');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  private close(): void {
    const el = document.getElementById('plan_form');
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
  }
}
