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

  /** Gateable feature modules a plan can unlock (mirrors backend SubscriptionPlan::MODULES). */
  readonly allModules: { key: string; label: string }[] = [
    {key: 'assessments', label: 'Assessments & question bank'},
    {key: 'worksheets', label: 'Worksheets'},
    {key: 'portfolio', label: 'Competency portfolio'},
    {key: 'live_classes', label: 'Live classes'},
    {key: 'analytics', label: 'Analytics & progress reports'},
    {key: 'interventions', label: 'Interventions'},
  ];
  selectedModules = signal<Set<string>>(new Set());

  hasModule(key: string): boolean { return this.selectedModules().has(key); }
  toggleModule(key: string): void {
    const next = new Set(this.selectedModules());
    next.has(key) ? next.delete(key) : next.add(key);
    this.selectedModules.set(next);
  }
  moduleLabel(key: string): string { return this.allModules.find(m => m.key === key)?.label ?? key; }

  form = new FormGroup({
    code: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    audience: new FormControl('school', {nonNullable: true}),
    priceNaira: new FormControl<number | null>(null),
    interval: new FormControl('termly', {nonNullable: true}),
    annualNaira: new FormControl<number | null>(null),
    periodDays: new FormControl<number | null>(null),
    isCustom: new FormControl(false, {nonNullable: true}),
    maxStudents: new FormControl<number | null>(null),
    maxTeachers: new FormControl<number | null>(null),
    maxAdmins: new FormControl<number | null>(null),
    storageGb: new FormControl<number | null>(null),
    sortOrder: new FormControl<number>(0, {nonNullable: true}),
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
    this.form.reset({interval: 'termly', audience: 'school', isActive: false, isCustom: false, sortOrder: 0});
    this.selectedModules.set(new Set(this.allModules.map(m => m.key)));
    this.form.get('code')!.enable();
    this.open();
  }

  openEdit(plan: any): void {
    this.editing.set(plan);
    this.form.reset({interval: plan.interval, isActive: plan.is_active, audience: plan.audience ?? 'school', isCustom: !!plan.is_custom, sortOrder: plan.sort_order ?? 0});
    this.form.patchValue({
      code: plan.code,
      name: plan.name,
      priceNaira: plan.price_naira,
      annualNaira: plan.annual_price_naira,
      periodDays: plan.period_days,
      maxStudents: plan.max_students,
      maxTeachers: plan.max_teachers,
      maxAdmins: plan.max_admins,
      storageGb: plan.storage_gb,
      features: (plan.features ?? []).join('\n'),
    });
    this.selectedModules.set(new Set(plan.modules ?? []));
    this.form.get('code')!.disable(); // code is the immutable key
    this.open();
  }

  save(): void {
    const v = this.form.getRawValue();
    if (this.form.get('name')!.invalid || (!this.editing() && this.form.get('code')!.invalid) || (!v.isCustom && !(Number(v.priceNaira) > 0))) {
      this.toast.error('A code, a name and a price are required (a custom-quote plan needs no price)');
      return;
    }
    const body: any = {
      code: v.code,
      name: v.name,
      audience: v.audience,
      price_kobo: Math.round((Number(v.priceNaira) || 0) * 100),
      interval: v.interval,
      annual_price_kobo: v.annualNaira ? Math.round(Number(v.annualNaira) * 100) : null,
      period_days: v.interval === 'cohort' ? (v.periodDays || null) : null,
      is_custom: v.isCustom,
      max_students: v.maxStudents,
      max_teachers: v.maxTeachers,
      max_admins: v.maxAdmins,
      storage_gb: v.storageGb,
      sort_order: Number(v.sortOrder) || 0,
      features: String(v.features || '').split('\n').map((f) => f.trim()).filter(Boolean),
      modules: [...this.selectedModules()],
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

  /** Put a draft plan on sale (or back on sale). */
  publish(plan: any): void {
    if (!confirm(`Put the ${plan.name} plan on sale? Organisations will be able to buy it from Billing at the prices shown.`)) return;
    this.api.put('/backend/billing/plans', {id: plan.id, is_active: true}).subscribe({
      next: () => { this.toast.success('Plan is on sale'); this.load(); },
      error: (e) => this.toast.error(e?.error?.error || 'Could not update the plan'),
    });
  }

  intervalLabel(i: string): string {
    return ({termly: 'term', monthly: 'month', yearly: 'year', cohort: 'cohort'} as Record<string, string>)[i] ?? i;
  }
  audienceLabel(a: string): string { return a === 'academy' ? 'Academies' : 'Schools'; }
  plansFor(audience: string): any[] { return this.plans().filter(p => (p.audience ?? 'school') === audience); }

  deactivate(plan: any): void {
    if (!confirm(`Take the ${plan.name} plan off sale? Organisations already on it keep it and can renew it; nobody new can buy it.`)) return;
    this.api.delete(`/backend/billing/plans?id=${plan.id}`, {confirm: false}).subscribe({
      next: () => { this.toast.success('Plan taken off sale'); this.load(); },
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
