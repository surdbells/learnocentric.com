import {Component, computed, inject, signal} from '@angular/core';
import {DatePipe, DecimalPipe} from '@angular/common';
import {ActivatedRoute, Router, RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {ModuleAccessService} from '../../../../../common/auth/module-access.service';

declare const bootstrap: any;

const STATUS_COLOR: Record<string, string> = {active: 'success', grace: 'warning', expired: 'danger', cancelled: 'secondary', none: 'secondary'};
const STATUS_LABEL: Record<string, string> = {active: 'Active', grace: 'Payment due', expired: 'Suspended', cancelled: 'Ended', none: 'No subscription'};
const INTERVAL_LABEL: Record<string, string> = {termly: 'term', yearly: 'year', monthly: 'month', cohort: 'cohort'};
const KIND_LABEL: Record<string, string> = {new: 'New subscription', renewal: 'Renewal', upgrade: 'Upgrade', downgrade: 'Change at renewal', subscription: 'Subscription'};

/**
 * Billing & Subscription for an organisation's administrator.
 *
 * Every plan choice is shown as an order first (what it is, the dates, the
 * one total payable) and only then paid. The server works out the order and
 * applies the rules; this page shows it and carries out the steps.
 */
@Component({
  selector: 'app-billing',
  standalone: true,
  imports: [Icon, PageHeader, DatePipe, DecimalPipe, LearnoModal, RouterLink],
  templateUrl: './billing.html',
  styleUrl: './billing.css',
})
export class Billing {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly moduleAccess = inject(ModuleAccessService);

  loading = signal(true);
  busy = signal(false);
  state = signal<any | null>(null);
  plans = signal<any[]>([]);
  invoices = signal<any[]>([]);
  /** The billing period the plan cards show prices for. */
  period = signal<'standard' | 'yearly'>('standard');

  /** The order being reviewed before payment. */
  order = signal<any | null>(null);
  orderPlan = signal<any | null>(null);
  /** A test-mode payment waiting to be completed (no Paystack key in development). */
  pendingRef = signal<string | null>(null);
  /** The invoice or receipt being viewed. */
  invoice = signal<any | null>(null);

  readonly sub = computed(() => this.state()?.subscription ?? null);
  readonly status = computed<string>(() => this.state()?.status ?? 'none');
  readonly anyYearly = computed(() => this.plans().some(p => (p.intervals ?? []).includes('yearly') && p.interval !== 'yearly'));
  readonly usageRows = computed(() => {
    const s = this.state();
    if (!s) return [];
    return [
      {key: 'learners', label: 'Learners', used: s.usage?.learners ?? 0, limit: s.limits?.learners},
      {key: 'teachers', label: 'Teachers / tutors', used: s.usage?.teachers ?? 0, limit: s.limits?.teachers},
      {key: 'admins', label: 'Administrators', used: s.usage?.admins ?? 0, limit: s.limits?.admins},
    ];
  });

  constructor() {
    // Returning from Paystack checkout (via /billing/callback): confirm the payment first.
    const q = this.route.snapshot.queryParamMap;
    const ref = q.get('reference') || q.get('trxref');
    if (ref) {
      this.confirmReturn(ref);
    } else {
      this.load();
    }
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/billing/subscription').subscribe({
      next: (res) => { this.state.set(res); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
    this.api.get<any>('/backend/billing/plans').subscribe({next: (r) => this.plans.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/billing/transactions').subscribe({next: (r) => this.invoices.set(r?.data ?? [])});
  }

  private confirmReturn(ref: string): void {
    this.loading.set(true);
    this.api.post<any>('/backend/billing/verify', {reference: ref}).subscribe({
      next: () => { this.toast.success('Payment confirmed. Your receipt is in Invoices below.'); this.afterReturn(); },
      error: (e) => { this.toast.error(e?.error?.error || 'We could not confirm that payment yet'); this.afterReturn(); },
    });
  }

  private afterReturn(): void {
    this.router.navigate([], {relativeTo: this.route, queryParams: {}, replaceUrl: true});
    this.load();
    this.moduleAccess.refresh();
  }

  // ---------------------------------------------------------------- plan cards

  isCurrent(plan: any): boolean { return this.sub()?.plan_id === plan.id; }
  /** The billing period a card shows: yearly when chosen and offered, otherwise the plan's own. */
  cardInterval(plan: any): string {
    return this.period() === 'yearly' && (plan.intervals ?? []).includes('yearly') ? 'yearly' : plan.interval;
  }
  cardPrice(plan: any): number | null {
    return this.cardInterval(plan) === 'yearly' && plan.interval !== 'yearly' ? plan.annual_price_naira : plan.price_naira;
  }
  /** The saving of a year paid up front against the standard periods it replaces, when it is one. */
  yearlySaving(plan: any): number | null {
    if (!plan.annual_price_naira || plan.interval === 'yearly') return null;
    const per = plan.interval === 'termly' ? 3 : plan.interval === 'monthly' ? 12 : 0;
    if (!per) return null;
    const full = plan.price_naira * per;
    return full > plan.annual_price_naira ? Math.round((1 - plan.annual_price_naira / full) * 100) : null;
  }
  buttonLabel(plan: any): string {
    if (plan.is_custom) return 'Contact us';
    if (this.isCurrent(plan) && this.status() === 'active') return 'Renew';
    if (this.isCurrent(plan)) return 'Renew now';
    return this.sub() && this.status() === 'active' ? 'Change to ' + plan.name : 'Choose ' + plan.name;
  }

  // ---------------------------------------------------------------- order and payment

  /** Ask the server what this choice means right now, and show it as an order. */
  review(plan: any): void {
    this.busy.set(true);
    this.api.post<any>('/backend/billing/quote', {plan_id: plan.id, interval: this.cardInterval(plan)}).subscribe({
      next: (q) => { this.busy.set(false); this.order.set(q); this.orderPlan.set(plan); this.open('billing_order'); },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'That plan cannot be chosen right now', undefined, {timeOut: 10000}); },
    });
  }

  /** Accept the order: pay through Paystack, or record a change that needs no payment. */
  placeOrder(): void {
    const q = this.order();
    if (!q) return;
    this.busy.set(true);
    this.api.post<any>('/backend/billing/subscribe', {plan_id: q.plan_id, interval: q.interval}).subscribe({
      next: (res) => {
        this.busy.set(false);
        if (res?.scheduled) {
          this.close('billing_order');
          this.toast.success('Change saved. It takes effect at your next renewal.');
          this.load();
          return;
        }
        if (res?.authorization_url) {
          window.location.href = res.authorization_url; // Paystack's hosted checkout
          return;
        }
        this.pendingRef.set(res?.reference ?? null); // test mode: confirm below
      },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'Could not start the payment'); },
    });
  }

  completeTestPayment(): void {
    const ref = this.pendingRef();
    if (!ref) return;
    this.busy.set(true);
    this.api.post<any>('/backend/billing/verify', {reference: ref}).subscribe({
      next: () => {
        this.busy.set(false);
        this.pendingRef.set(null);
        this.close('billing_order');
        this.toast.success('Payment confirmed. Your receipt is in Invoices below.');
        this.load();
        this.moduleAccess.refresh();
      },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'Verification failed'); },
    });
  }

  // ---------------------------------------------------------------- renewal

  stopRenewal(): void {
    const s = this.sub();
    if (!s) return;
    const until = new Date(s.paid_until).toLocaleDateString(undefined, {day: 'numeric', month: 'long', year: 'numeric'});
    if (!confirm(`Stop renewal? Access continues until ${until}, then tutors and learners can no longer use the platform. Nothing already paid is refunded. You can resume before then.`)) return;
    this.post('/backend/billing/cancel-renewal', (r) => this.toast.success(r?.message || 'Renewal stopped'));
  }

  resumeRenewal(): void { this.post('/backend/billing/resume-renewal', () => this.toast.success('Renewal resumed')); }
  clearChange(): void { this.post('/backend/billing/clear-change', () => this.toast.success('The change was removed')); }

  private post(url: string, done: (r: any) => void): void {
    this.busy.set(true);
    this.api.post<any>(url, {}).subscribe({
      next: (r) => { this.busy.set(false); done(r); this.load(); this.moduleAccess.refresh(); },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'That could not be done'); },
    });
  }

  // ---------------------------------------------------------------- invoices

  viewInvoice(t: any): void { this.invoice.set(t); this.open('billing_invoice'); }
  /** Print just the invoice or receipt (the browser's print dialog can also save it as a PDF). */
  printInvoice(): void {
    if (typeof window === 'undefined') return;
    const doc = document.querySelector('#billing_invoice .billing-doc');
    const w = window.open('', '_blank', 'width=800,height=900');
    if (!doc || !w) { window.print(); return; }
    const title = (this.invoice()?.status === 'success' ? 'Receipt ' : 'Invoice ') + (this.invoice()?.reference ?? '');
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${title}</title><style>
      body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#1f2937;margin:32px;font-size:14px}
      table{width:100%;border-collapse:collapse;margin:12px 0}td{padding:6px 0;border-bottom:1px solid #e5e7eb}
      .text-end{text-align:right}.fw-semibold{font-weight:600}.small{font-size:13px}.text-body-secondary{color:#6b7280}
      .text-success{color:#15803d}.font-monospace{font-family:ui-monospace,monospace}
      .d-flex{display:flex}.justify-content-between{justify-content:space-between}.gap-2{gap:8px}.mb-3{margin-bottom:16px}.mb-2{margin-bottom:8px}
    </style></head><body><h2 style="margin:0 0 16px">LearnoCentric ${title.split(' ')[0].toLowerCase()}</h2>${doc.innerHTML}</body></html>`);
    w.document.close();
    w.focus();
    w.print();
  }

  // ---------------------------------------------------------------- labels

  statusColor(s: string | undefined): string { return STATUS_COLOR[s ?? 'none'] ?? 'secondary'; }
  statusLabel(s: string | undefined): string { return STATUS_LABEL[s ?? 'none'] ?? s ?? ''; }
  intervalLabel(i: string | undefined): string { return INTERVAL_LABEL[i ?? ''] ?? i ?? ''; }
  kindLabel(k: string | undefined): string { return KIND_LABEL[k ?? ''] ?? k ?? ''; }
  invColor(s: string): string { return s === 'success' ? 'success' : (s === 'failed' ? 'danger' : 'warning'); }
  invLabel(s: string): string { return s === 'success' ? 'Paid' : (s === 'failed' ? 'Failed' : 'Not paid'); }
  naira(kobo: number | null | undefined): number { return (kobo ?? 0) / 100; }
  pct(used: number, limit: number | null | undefined): number { return limit ? Math.min(100, Math.round(used / limit * 100)) : 0; }

  private open(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }

  private close(id: string): void {
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
  }
}
