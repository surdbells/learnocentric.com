import {Component, computed, inject, PLATFORM_ID, signal} from '@angular/core';
import {DatePipe, isPlatformBrowser} from '@angular/common';
import {RouterLink} from '@angular/router';
import {AuthService} from '../../../../../common/auth/auth.service';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

@Component({
  selector: 'app-student-dashboard',
  standalone: true,
  imports: [Icon, RouterLink, DatePipe],
  templateUrl: './student-dashboard.html',
  styleUrl: './student-dashboard.css',
})
export class StudentDashboard {
  private readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly platformId = inject(PLATFORM_ID);

  loading = signal(true);
  data = signal<any | null>(null);
  firstName = signal('');

  constructor() {
    this.firstName.set(this.auth.getAuthSession()?.user?.firstName ?? 'there');
    if (isPlatformBrowser(this.platformId)) {
      this.api.get<any>('/backend/dashboard/student').subscribe({
        next: (res) => { this.data.set(res); this.loading.set(false); },
        error: () => this.loading.set(false),
      });
    }
  }

  scoreColor(p: number | null): string {
    if (p === null || p === undefined) return 'secondary';
    if (p >= 70) return 'success';
    if (p >= 50) return 'warning';
    return 'danger';
  }
  pct(v: number | null): string { return v === null || v === undefined ? '—' : v + '%'; }

  // --- SVG gauges ---
  readonly gaugeCirc = 2 * Math.PI * 52; // r = 52

  gaugeOffset(pctVal: number | null): number {
    const p = Math.max(0, Math.min(100, Number(pctVal ?? 0)));
    return this.gaugeCirc * (1 - p / 100);
  }
  gaugeStroke(color: string): string {
    return ({success: '#22c55e', warning: '#f59e0b', danger: '#ef4444', info: '#0ea5e9'} as Record<string, string>)[color] ?? '#39c645';
  }

  readonly lessonPct = computed<number>(() => {
    const s = this.data()?.stats;
    if (!s || !s.topics) return 0;
    return Math.round((s.lessons_viewed / s.topics) * 100);
  });

  /** Recent quiz percentages, oldest→newest, as an SVG area + line path. */
  readonly trend = computed(() => {
    const qs = (this.data()?.recent_quizzes ?? []).slice().reverse();
    if (qs.length < 2) return null;
    const w = 320, h = 96, pad = 8;
    const vals = qs.map((q: any) => Math.max(0, Math.min(100, Number(q.percentage) || 0)));
    const stepX = (w - pad * 2) / (vals.length - 1);
    const pts = vals.map((v: number, i: number) => ({
      x: pad + i * stepX,
      y: pad + (h - pad * 2) * (1 - v / 100),
    }));
    const line = pts.map((p: any) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
    const area = `M${pad},${h - pad} L` + line.split(' ').join(' L') + ` L${(w - pad).toFixed(1)},${h - pad} Z`;
    return {w, h, line, area, pts, last: pts[pts.length - 1]};
  });
}
