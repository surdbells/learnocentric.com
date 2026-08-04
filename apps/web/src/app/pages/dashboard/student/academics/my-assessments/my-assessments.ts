import {Component, computed, inject, OnDestroy, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {forkJoin, of} from 'rxjs';
import {catchError} from 'rxjs/operators';
import {AuthService} from '../../../../../common/auth/auth.service';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';
import {KpiItem, KpiStrip, TabBar, TabItem, LineChart, BarList, BarItem, StatRing} from '../../../../../common/ui';
import {Tone} from '../../../../../common/ui/ui-types';

@Component({
  selector: 'app-my-assessments',
  standalone: true,
  imports: [Icon, PageHeader, FormsModule, DatePipe, KpiStrip, TabBar, LineChart, BarList, StatRing],
  templateUrl: './my-assessments.html',
  styleUrl: './my-assessments.css',
})
export class MyAssessments implements OnDestroy {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastrService);
  private readonly platformId = inject(PLATFORM_ID);

  analytics = signal<any | null>(null);

  mode = signal<'list' | 'take' | 'result'>('list');
  loading = signal(false);
  busy = signal(false);
  available = signal<any[]>([]);
  current = signal<any | null>(null);
  result = signal<any | null>(null);
  responses = signal<Record<number, any>>({});
  activeTab = signal<string>('all');

  // Timed-quiz engine
  remaining = signal<number>(0); // seconds left
  private timerId: any = null;
  private deadline = 0;          // epoch ms; 0 = untimed

  readonly graded = computed<any[]>(() => this.available().filter(x => x.attempt?.status === 'graded' && x.attempt?.percentage != null));
  readonly avgScore = computed<number | null>(() => {
    const g = this.graded();
    return g.length ? Math.round(g.reduce((s, x) => s + x.attempt.percentage, 0) / g.length) : null;
  });

  readonly kpis = computed<KpiItem[]>(() => {
    const a = this.available(), g = this.graded();
    const avg = this.avgScore();
    const best = g.length ? Math.max(...g.map(x => x.attempt.percentage)) : null;
    const quizzes = g.filter(x => x.type === 'quiz').length;
    const assessments = g.filter(x => x.type !== 'quiz').length;
    const areas = (this.analytics()?.topic_mastery ?? []).filter((t: any) => t.average < 60).length;
    return [
      {label: 'Overall average', value: avg === null ? '—' : avg + '%', icon: 'workspace_premium', tone: avg === null ? 'secondary' : avg >= 70 ? 'success' : avg >= 50 ? 'warning' : 'danger'},
      {label: 'Quizzes taken', value: quizzes, icon: 'quiz', tone: 'info'},
      {label: 'Assessments taken', value: assessments, icon: 'assignment_turned_in', tone: 'primary'},
      {label: 'Best score', value: best === null ? '—' : best + '%', icon: 'star', tone: 'success'},
      {label: 'Areas to improve', value: areas, sublabel: 'below mastery', icon: 'monitoring', tone: areas > 0 ? 'danger' : 'success'},
    ];
  });

  /** Assessments not yet completed (upcoming/open). */
  readonly upcoming = computed<any[]>(() => this.available().filter(x => x.attempt?.status !== 'graded'));

  /** Graded attempts as recent results, newest first. */
  readonly recentResults = computed<any[]>(() =>
    this.graded()
      .map(x => ({title: x.title, type: x.type, subject: x.subject, percentage: x.attempt.percentage,
        score: x.attempt.score, total: x.attempt.total_marks, band: this.perfBand(x.attempt.percentage)}))
      .slice(0, 6));

  // Performance overview (per-student trend) + topic performance
  readonly trendSeries = computed<number[]>(() => (this.analytics()?.performance_trend ?? []).map((m: any) => m.average ?? 0));
  readonly trendLabels = computed<string[]>(() => (this.analytics()?.performance_trend ?? []).map((m: any) => this.monthLabel(m.month)));
  readonly topicBars = computed<BarItem[]>(() =>
    (this.analytics()?.topic_mastery ?? []).slice(0, 5).map((t: any) => ({label: t.topic, value: t.average,
      tone: (t.average >= 70 ? 'success' : t.average >= 50 ? 'warning' : 'danger') as Tone})));

  perfBand(p: number): string { return p >= 80 ? 'Excellent' : p >= 65 ? 'Good' : p >= 50 ? 'Fair' : 'Needs work'; }
  perfTone(p: number): string { return p >= 80 ? 'success' : p >= 65 ? 'primary' : p >= 50 ? 'warning' : 'danger'; }
  monthLabel(ym: string): string {
    const [y, m] = (ym ?? '').split('-').map(Number);
    if (!y || !m) return ym;
    return new Date(y, m - 1, 1).toLocaleString('en', {month: 'short'});
  }

  readonly tabs = computed<TabItem[]>(() => {
    const a = this.available();
    const types = [...new Set(a.map(x => x.type).filter(Boolean))] as string[];
    return [
      {key: 'all', label: 'All', count: a.length},
      ...types.map(t => ({key: t, label: this.titleCase(t), count: a.filter(x => x.type === t).length})),
    ];
  });

  readonly filtered = computed<any[]>(() => {
    const t = this.activeTab(), a = this.available();
    return t === 'all' ? a : a.filter(x => x.type === t);
  });

  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

  constructor() {
    this.loadAvailable();
  }

  loadAvailable(): void {
    this.loading.set(true);
    const myId = this.auth.getAuthSession()?.user?.id;
    forkJoin({
      available: this.api.get<any>('/backend/assessment/attempts/available'),
      analytics: myId ? this.api.get<any>(`/backend/analytics/student/${myId}`).pipe(catchError(() => of(null))) : of(null),
    }).subscribe({
      next: (res) => { this.available.set(res.available?.data ?? []); this.analytics.set(res.analytics); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your assessments'); },
    });
  }

  start(a: any): void {
    this.busy.set(true);
    this.api.post<any>('/backend/assessment/attempts', {assessment_id: a.id}).subscribe({
      next: (attempt) => {
        this.current.set(attempt);
        this.responses.set({});
        this.mode.set('take');
        this.busy.set(false);
        this.startTimer(attempt);
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not start'); this.busy.set(false); },
    });
  }

  viewResult(attemptId: number): void {
    this.busy.set(true);
    this.api.get<any>(`/backend/assessment/attempts/${attemptId}`).subscribe({
      next: (res) => { this.result.set(res); this.mode.set('result'); this.busy.set(false); },
      error: () => { this.toast.error('Could not load result'); this.busy.set(false); },
    });
  }

  setResponse(questionId: number, value: any): void {
    this.responses.set({...this.responses(), [questionId]: value});
  }

  isChecked(questionId: number, key: string): boolean {
    const v = this.responses()[questionId];
    return Array.isArray(v) && v.includes(key);
  }

  toggleMulti(questionId: number, key: string, checked: boolean): void {
    const current = this.responses()[questionId];
    const selected: string[] = Array.isArray(current) ? [...current] : [];
    const idx = selected.indexOf(key);
    if (checked && idx < 0) selected.push(key);
    if (!checked && idx >= 0) selected.splice(idx, 1);
    this.setResponse(questionId, selected);
  }

  answered(): number {
    return Object.values(this.responses()).filter((v) => this.isAnswered(v)).length;
  }

  isAnswered(v: any): boolean {
    return v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && v.length === 0);
  }

  // --- Timed-quiz engine ---
  /** True when this attempt has a countdown running. */
  timed(): boolean { return this.deadline > 0; }

  private startTimer(att: any): void {
    this.clearTimer();
    this.deadline = 0;
    const dur = Number(att?.duration_minutes) || 0;
    if (!dur || !att?.started_at || !isPlatformBrowser(this.platformId)) return;
    this.deadline = new Date(att.started_at).getTime() + dur * 60_000;
    this.tick();
    this.timerId = setInterval(() => this.tick(), 1000);
  }

  private tick(): void {
    const secs = Math.max(0, Math.round((this.deadline - Date.now()) / 1000));
    this.remaining.set(secs);
    if (secs <= 0) {
      this.clearTimer();
      if (this.mode() === 'take' && !this.busy()) {
        this.toast.info('Time is up — submitting your answers.');
        this.submit();
      }
    }
  }

  private clearTimer(): void {
    if (this.timerId) { clearInterval(this.timerId); this.timerId = null; }
  }

  formatTime(s: number): string {
    const m = Math.floor(s / 60), sec = s % 60;
    return `${m}:${sec.toString().padStart(2, '0')}`;
  }

  /** Timer is in the last 60 seconds — used to flash the badge. */
  timeCritical(): boolean { return this.timed() && this.remaining() <= 60; }

  /** Question-navigator: jump to a question and report whether it's answered. */
  qAnswered(qid: number): boolean { return this.isAnswered(this.responses()[qid]); }
  scrollToQ(qid: number): void {
    if (isPlatformBrowser(this.platformId)) {
      document.getElementById('q_' + qid)?.scrollIntoView({behavior: 'smooth', block: 'center'});
    }
  }

  ngOnDestroy(): void { this.clearTimer(); }

  submit(): void {
    const attempt = this.current();
    if (!attempt) return;
    this.clearTimer();
    const answers = (attempt.questions ?? []).map((q: any) => ({question_id: q.question_id, response: this.responses()[q.question_id] ?? null}));
    this.busy.set(true);
    this.api.post<any>(`/backend/assessment/attempts/${attempt.id}/submit`, {answers}).subscribe({
      next: (res) => { this.result.set(res); this.mode.set('result'); this.busy.set(false); },
      error: (e) => { this.toast.error(e?.error?.error || 'Submit failed'); this.busy.set(false); },
    });
  }

  backToList(): void {
    this.clearTimer();
    this.current.set(null);
    this.result.set(null);
    this.mode.set('list');
    this.loadAvailable();
  }

  responseText(r: any): string {
    if (r === null || r === undefined || r === '') return '—';
    if (Array.isArray(r)) return r.length ? r.join(' / ') : '—';
    return String(r);
  }

  answerText(a: any): string {
    const ca = a?.correct_answer;
    if (ca === null || ca === undefined) return '';
    if (Array.isArray(ca)) return ca.join(' / ');
    if (typeof ca === 'object') return String(ca.value ?? '');
    return String(ca);
  }
}
