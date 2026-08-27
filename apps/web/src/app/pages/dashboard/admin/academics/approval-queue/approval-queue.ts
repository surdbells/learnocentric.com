import {Component, computed, inject, signal, PLATFORM_ID} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LearnoModal} from '../../../../../components/learno-modal/learno-modal';
import {RichText} from '../../../../../common/rich-editor/rich-text';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, TabBar, TabItem} from '../../../../../common/ui';

declare const bootstrap: any;

/** Governed type -> its REST base (the transition endpoint is `${base}/${id}/transition`). */
const TYPE_ROUTE: Record<string, string> = {
  Topic: '/backend/curriculum/topics',
  TopicDeliveryPack: '/backend/curriculum/delivery-packs',
  Question: '/backend/assessment/questions',
  Assessment: '/backend/assessment/assessments',
  Worksheet: '/backend/assessment/worksheets',
  SchemeOfWork: '/backend/school/scheme-of-work',
  ContentPackage: '/backend/content/packages',
};

const arr = (r: any): any[] => Array.isArray(r) ? r : (r?.data ?? []);

/**
 * How to fetch the full content of a queued item for preview. Types with a `:show`
 * endpoint are fetched directly; the rest are located by id in their list endpoint.
 */
const DETAIL: Record<string, {url: (id: number) => string; pick: (r: any, id: number) => any}> = {
  Assessment: {url: (id) => `/backend/assessment/assessments/${id}`, pick: (r) => r?.data ?? r},
  TopicDeliveryPack: {url: (id) => `/backend/curriculum/delivery-packs/${id}`, pick: (r) => r?.data ?? r},
  ContentPackage: {url: (id) => `/backend/content/packages/${id}`, pick: (r) => r?.data ?? r},
  Question: {url: () => '/backend/assessment/questions', pick: (r, id) => arr(r).find((x) => x.id === id)},
  Topic: {url: () => '/backend/curriculum/topics', pick: (r, id) => arr(r).find((x) => x.id === id)},
  Worksheet: {url: () => '/backend/assessment/worksheets', pick: (r, id) => arr(r).find((x) => x.id === id)},
  SchemeOfWork: {url: () => '/backend/school/scheme-of-work', pick: (r, id) => arr(r).find((x) => x.id === id)},
};

const STATUS_COLOR: Record<string, string> = {
  draft: 'secondary', review: 'warning', approved: 'info', published: 'success', archived: 'dark',
};

/** Friendly labels for question formats (raw values are terse codes). */
const QTYPE_LABEL: Record<string, string> = {
  mcq: 'Multiple choice', multi: 'Multiple answer', true_false: 'True / False',
  short: 'Short answer', numeric: 'Numeric',
};

interface PreviewField {label: string; value: string; rich: boolean}

const TYPE_META: Record<string, {label: string; icon: string}> = {
  Topic: {label: 'Topic', icon: 'subject'},
  TopicDeliveryPack: {label: 'Delivery pack', icon: 'layers'},
  Question: {label: 'Question', icon: 'quiz'},
  Assessment: {label: 'Assessment', icon: 'assignment'},
  Worksheet: {label: 'Worksheet', icon: 'description'},
  SchemeOfWork: {label: 'Scheme of work', icon: 'calendar_month'},
  ContentPackage: {label: 'Content package', icon: 'folder'},
};

@Component({
  selector: 'app-approval-queue',
  standalone: true,
  imports: [PageHeader, Icon, DatePipe, KpiStrip, TabBar, LearnoModal, RichText],
  templateUrl: './approval-queue.html',
  styleUrl: './approval-queue.css',
})
export class ApprovalQueue {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  items = signal<any[]>([]);
  activeTab = signal<string>('all');
  busyKey = signal<string | null>(null); // `${type}:${id}` currently transitioning

  // --- Review preview modal ---
  reviewItem = signal<any | null>(null);   // the queue row being previewed
  reviewObj = signal<any | null>(null);     // its fetched full content
  reviewLoading = signal(false);

  readonly reviewFields = computed<PreviewField[]>(() => {
    const it = this.reviewItem(), o = this.reviewObj();
    return it && o ? this.buildFields(it.type, o) : [];
  });

  /** Assessment previews list their questions inline. */
  readonly reviewQuestions = computed<any[]>(() => {
    const it = this.reviewItem(), o = this.reviewObj();
    return it?.type === 'Assessment' ? (o?.questions ?? []) : [];
  });

  readonly kpis = computed<KpiItem[]>(() => {
    const it = this.items();
    const oldest = it.length ? it.reduce((a, b) => a.updated_at < b.updated_at ? a : b) : null;
    return [
      {label: 'Awaiting approval', value: it.length, icon: 'inbox', tone: it.length > 0 ? 'warning' : 'success'},
      {label: 'Content types', value: new Set(it.map(x => x.type)).size, icon: 'layers', tone: 'info'},
      {label: 'Assessments', value: it.filter(x => x.type === 'Assessment' || x.type === 'Question').length, icon: 'quiz', tone: 'primary'},
      {label: 'Oldest waiting', value: oldest ? this.waited(oldest.updated_at) : '-', icon: 'schedule', tone: 'secondary'},
    ];
  });

  readonly tabs = computed<TabItem[]>(() => {
    const it = this.items();
    const types = [...new Set(it.map(x => x.type))] as string[];
    return [
      {key: 'all', label: 'All', count: it.length},
      ...types.map(t => ({key: t, label: this.typeLabel(t), count: it.filter(x => x.type === t).length})),
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), it = this.items();
    return t === 'all' ? it : it.filter(x => x.type === t);
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/curriculum/review-queue').subscribe({
      next: (r) => { this.items.set(r?.data ?? []); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load the approval queue'); },
    });
  }

  /** Open the preview modal and load the item's full content. */
  review(item: any): void {
    this.reviewItem.set(item);
    this.reviewObj.set(null);
    this.reviewLoading.set(true);
    this.open('review_item');
    const cfg = DETAIL[item.type];
    if (!cfg) { this.reviewLoading.set(false); return; }
    this.api.get<any>(cfg.url(item.id)).subscribe({
      next: (r) => { this.reviewObj.set(cfg.pick(r, item.id) ?? null); this.reviewLoading.set(false); },
      error: () => { this.reviewLoading.set(false); this.toast.error('Could not load this item to preview.'); },
    });
  }

  /** Approve / return from inside the preview modal, then close it. */
  reviewApprove(): void { const it = this.reviewItem(); if (it) { this.close('review_item'); this.approve(it); } }
  reviewReturn(): void { const it = this.reviewItem(); if (it) { this.close('review_item'); this.return(it); } }

  approve(item: any): void {
    this.transition(item, 'approved', null, 'approved');
  }

  return(item: any): void {
    const note = isPlatformBrowser(this.platformId)
      ? (window.prompt('Reason for returning to the author (optional):') ?? '')
      : '';
    // A null return from prompt means the user cancelled.
    if (isPlatformBrowser(this.platformId) && note === null) return;
    this.transition(item, 'draft', note, 'returned to the author');
  }

  private transition(item: any, to: string, note: string | null, verb: string): void {
    const base = TYPE_ROUTE[item.type];
    if (!base) { this.toast.error('This item type cannot be actioned here.'); return; }
    const key = `${item.type}:${item.id}`;
    this.busyKey.set(key);
    this.api.post<any>(`${base}/${item.id}/transition`, {to, note}).subscribe({
      next: () => {
        this.busyKey.set(null);
        this.toast.success(`${this.typeLabel(item.type)} ${verb}`);
        // Drop it from the queue, it's no longer in review.
        this.items.set(this.items().filter(x => !(x.type === item.type && x.id === item.id)));
      },
      error: (e) => { this.busyKey.set(null); this.toast.error(e?.error?.error || 'Could not update this item'); },
    });
  }

  isBusy(item: any): boolean { return this.busyKey() === `${item.type}:${item.id}`; }
  typeLabel(t: string): string { return TYPE_META[t]?.label ?? t; }
  typeIcon(t: string): string { return TYPE_META[t]?.icon ?? 'description'; }
  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }

  /** Build the ordered, human-labelled preview fields for a governed item. */
  private buildFields(type: string, o: any): PreviewField[] {
    const F = (label: string, value: any, rich = false): PreviewField & {present: boolean} =>
      ({label, value: this.norm(value), rich, present: this.has(value)});
    let raw: (PreviewField & {present: boolean})[];
    switch (type) {
      case 'Assessment':
        raw = [F('Format', this.pretty(o.type)), F('Track', this.pretty(o.track)),
          F('Duration', o.duration_minutes ? `${o.duration_minutes} min` : null),
          F('Pass mark', o.pass_mark != null ? `${o.pass_mark}%` : null),
          F('Questions', o.question_count), F('Total marks', o.total_marks),
          F('Instructions', o.instructions, true)];
        break;
      case 'Question':
        raw = [F('Question', o.stem, true), F('Format', this.qType(o.type)),
          F('Difficulty', this.pretty(o.difficulty)), F('Topic', o.topic),
          F('Options', this.formatOptions(o.options)),
          F('Correct answer', this.pretty(o.correct_answer)),
          F('Marks', o.marks), F('Explanation', o.explanation, true),
          F('Misconception tag', o.misconception_tag)];
        break;
      case 'Topic':
        raw = [F('Learning objective', o.objective, true), F('Strand', o.strand),
          F('Week', o.week_number), F('Prerequisites', o.prerequisites, true),
          F('Core theory', o.core_theory, true), F('Common misconceptions', o.misconceptions, true),
          F('Real-life relevance', o.real_life_relevance, true),
          F('Workplace relevance', o.workplace_relevance, true),
          F('Competency built', o.competency_built),
          F('Portfolio evidence expected', o.portfolio_evidence_expected, true)];
        break;
      case 'TopicDeliveryPack': {
        const p = o.pack ?? o, t = o.topic ?? {};
        raw = [F('Topic', t.title), F('Objective', t.objective, true),
          F('Class', t.class_label), F('Week', t.week_number),
          F('Version', p.version), F('Lesson note', p.learner_note, true)];
        break;
      }
      default:
        raw = Object.keys(o ?? {})
          .filter((k) => !['id', 'next_states', 'version_count', 'approval_status', 'moderation_status'].includes(k))
          .map((k) => F(this.pretty(k), o[k]));
    }
    return raw.filter((f) => f.present).map(({label, value, rich}) => ({label, value, rich}));
  }

  private has(v: any): boolean {
    return v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && !v.length);
  }
  private norm(v: any): string {
    if (Array.isArray(v)) return v.join(', ');
    return String(v);
  }
  /** Title-case a snake_case / lowercase token for display. */
  pretty(s: any): string {
    if (s === null || s === undefined || s === '') return '';
    return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }
  /** Friendly label for a question format code. */
  qType(t: any): string { return QTYPE_LABEL[String(t)] ?? this.pretty(t); }
  /** Render an options list (array of strings or {key,text} objects) as labelled lines. */
  private formatOptions(opts: any): string | null {
    if (!Array.isArray(opts) || !opts.length) return null;
    return opts.map((x, i) => {
      const label = typeof x === 'string' ? x : (x.text ?? x.label ?? x.value ?? JSON.stringify(x));
      const key = (typeof x === 'object' && x?.key) ? x.key : String.fromCharCode(65 + i);
      return `${key}. ${label}`;
    }).join('\n');
  }

  private open(id: string): void {
    if (!isPlatformBrowser(this.platformId)) return;
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(el).show();
  }
  private close(id: string): void {
    if (!isPlatformBrowser(this.platformId)) return;
    const el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') bootstrap.Modal.getInstance(el)?.hide();
  }

  /** Human "waiting for" label from an ISO timestamp. */
  waited(iso: string): string {
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
    if (days <= 0) return 'today';
    if (days === 1) return '1 day';
    return `${days} days`;
  }
}
