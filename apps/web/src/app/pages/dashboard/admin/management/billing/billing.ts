import {Component, inject, signal} from '@angular/core';
import {DatePipe, DecimalPipe} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

const STATUS_COLOR: Record<string, string> = {active: 'success', grace: 'warning', expired: 'danger', cancelled: 'secondary', none: 'secondary'};

@Component({
  selector: 'app-billing',
  standalone: true,
  imports: [Icon, PageHeader, DatePipe, DecimalPipe],
  templateUrl: './billing.html',
  styleUrl: './billing.css',
})
export class Billing {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  busy = signal<number | null>(null);
  sub = signal<any | null>(null);
  hasAccess = signal(false);
  paystackLive = signal(false);
  plans = signal<any[]>([]);
  invoices = signal<any[]>([]);
  pendingRef = signal<string | null>(null);
  pendingPlan = signal<string | null>(null);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/billing/subscription').subscribe({
      next: (res) => {
        this.sub.set(res?.subscription ?? null);
        this.hasAccess.set(!!res?.has_access);
        this.paystackLive.set(!!res?.paystack_live);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
    this.api.get<any>('/backend/billing/plans').subscribe({next: (r) => this.plans.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/billing/transactions').subscribe({next: (r) => this.invoices.set(r?.data ?? [])});
  }

  isCurrent(plan: any): boolean { return this.sub()?.plan_code === plan.code; }

  subscribe(plan: any): void {
    this.busy.set(plan.id);
    this.api.post<any>('/backend/billing/subscribe', {plan_id: plan.id}).subscribe({
      next: (res) => {
        this.busy.set(null);
        if (res?.authorization_url) {
          // Live Paystack: hand off to the hosted checkout.
          window.location.href = res.authorization_url;
        } else {
          // Test mode: show the confirm step.
          this.pendingRef.set(res?.reference ?? null);
          this.pendingPlan.set(plan.name);
        }
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not start checkout'); this.busy.set(null); },
    });
  }

  completePayment(): void {
    const ref = this.pendingRef();
    if (!ref) return;
    this.busy.set(-1);
    this.api.post<any>('/backend/billing/verify', {reference: ref}).subscribe({
      next: () => {
        this.toast.success('Payment confirmed, subscription updated');
        this.pendingRef.set(null);
        this.pendingPlan.set(null);
        this.busy.set(null);
        this.load();
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Verification failed'); this.busy.set(null); },
    });
  }

  cancelPending(): void { this.pendingRef.set(null); this.pendingPlan.set(null); }

  statusColor(s: string | undefined): string { return STATUS_COLOR[s ?? 'none'] ?? 'secondary'; }
  invColor(s: string): string { return s === 'success' ? 'success' : (s === 'failed' ? 'danger' : 'warning'); }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
