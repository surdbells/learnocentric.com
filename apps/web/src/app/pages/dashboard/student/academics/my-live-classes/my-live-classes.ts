import {Component, OnDestroy, computed, inject, signal} from '@angular/core';
import {DatePipe} from '@angular/common';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {LiveRoom} from '../../../../../common/live-room/live-room';
import {ApiService} from '../../../../../common/service/api.service';
import {Icon} from '../../../../../common/icon/icon';

const STATUS_COLOR: Record<string, string> = {scheduled: 'info', live: 'success', ended: 'secondary', cancelled: 'dark'};

/** Subject-color accents cycle so the class list reads like the design's coloured tiles. */
const ACCENTS = ['primary', 'success', 'warning', 'info', 'danger'];

/**
 * Learner Live Classes list (design: Live Classes_LD) — Upcoming / Past tabs,
 * a next-class countdown hero, today's schedule rail, and class rules. Backed
 * by /live-classes/board (real classes + attendance). The design's "seats
 * left" and past-class video recordings have no data source, so they are
 * omitted rather than fabricated; "Test My Connection" runs a real client-side
 * check and "Join with Class Code" is dropped (there is no class-code model).
 */
@Component({
  selector: 'app-my-live-classes',
  standalone: true,
  imports: [Icon, PageHeader, DatePipe, RouterLink, LiveRoom],
  templateUrl: './my-live-classes.html',
  styleUrl: './my-live-classes.css',
})
export class MyLiveClasses implements OnDestroy {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(false);
  busy = signal<number | null>(null);
  board = signal<any>(null);
  activeTab = signal<'upcoming' | 'past'>('upcoming');
  room = signal<{ roomUrl: string; token: string | null; title: string } | null>(null);
  now = signal<number>(0);
  testing = signal(false);

  private timer?: ReturnType<typeof setInterval>;

  readonly next = computed(() => this.board()?.next ?? null);
  readonly upcoming = computed<any[]>(() => this.board()?.upcoming ?? []);
  readonly past = computed<any[]>(() => this.board()?.past ?? []);
  readonly today = computed<any[]>(() => this.board()?.today ?? []);

  /** Seconds until the next class starts (0 once it's due / live). */
  readonly countdown = computed(() => {
    const n = this.next();
    if (!n) return null;
    if (n.status === 'live') return {live: true, h: '00', m: '00', s: '00'};
    const diff = Math.max(0, Math.floor((new Date(n.scheduled_at).getTime() - this.now()) / 1000));
    return {
      live: false,
      h: String(Math.floor(diff / 3600)).padStart(2, '0'),
      m: String(Math.floor((diff % 3600) / 60)).padStart(2, '0'),
      s: String(diff % 60).padStart(2, '0'),
    };
  });

  constructor() {
    this.load();
    this.now.set(Date.now());
    this.timer = setInterval(() => this.now.set(Date.now()), 1000);
  }

  ngOnDestroy(): void {
    if (this.timer) clearInterval(this.timer);
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/live-classes/board').subscribe({
      next: (res) => { this.board.set(res ?? {}); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your classes'); },
    });
  }

  join(lc: any): void {
    if (lc.status !== 'live') {
      this.toast.info('This class hasn\'t started yet — please wait for the host to go live.');
      return;
    }
    this.busy.set(lc.id);
    this.api.post<any>(`/backend/live-classes/${lc.id}/join`, {}).subscribe({
      next: (res) => {
        this.busy.set(null);
        if (res?.room_url) {
          this.room.set({roomUrl: res.room_url, token: res.token ?? null, title: res.title || lc.title});
        } else {
          this.toast.error('This class has no room yet.');
        }
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not join'); this.busy.set(null); },
    });
  }

  leaveRoom(): void {
    this.room.set(null);
    this.load();
  }

  /** Client-side connection check — network + camera/mic availability. */
  async testConnection(): Promise<void> {
    this.testing.set(true);
    if (!navigator.onLine) {
      this.toast.error('You appear to be offline. Check your internet connection.');
      this.testing.set(false);
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({video: true, audio: true});
      stream.getTracks().forEach(t => t.stop());
      this.toast.success('Connection looks good — camera and microphone are working.');
    } catch {
      this.toast.warning('Online, but we couldn\'t access your camera/microphone. Check browser permissions.');
    } finally {
      this.testing.set(false);
    }
  }

  accent(i: number): string { return ACCENTS[i % ACCENTS.length]; }
  statusColor(s: string): string { return STATUS_COLOR[s] ?? 'secondary'; }
  titleCase(s: string): string { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
}
