import {afterNextRender, Component, ElementRef, EventEmitter, inject, Input, OnDestroy, Output, signal, ViewChild} from '@angular/core';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {AuthService} from '../auth/auth.service';
import {Icon} from '../icon/icon';

/**
 * Embedded Daily Prebuilt call. Instead of redirecting to the *.daily.co room,
 * this mounts Daily Prebuilt in an iframe inside the app and joins with the
 * per-user meeting token (identity + host privileges) issued by the backend.
 *
 * @daily-co/daily-js is browser-only, so it is dynamically imported after the
 * first render (SSR-safe), mirroring how ApexCharts is loaded elsewhere.
 */
@Component({
  selector: 'app-live-room',
  standalone: true,
  imports: [Icon, RouterLink],
  templateUrl: './live-room.html',
  styleUrl: './live-room.css',
})
export class LiveRoom implements OnDestroy {
  @Input({required: true}) roomUrl!: string;
  @Input() token: string | null = null;
  @Input() title = 'Live class';
  @Output() closed = new EventEmitter<void>();

  @ViewChild('callContainer', {static: true}) container!: ElementRef<HTMLDivElement>;

  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);
  private frame: any = null;

  /** The learner "report a concern" affordance is student-only (its route is under /student). */
  protected readonly isStudent = this.auth.getAuthSession()?.user?.role === 'student';

  connecting = signal(true);
  error = signal<string | null>(null);

  constructor() {
    afterNextRender(() => this.init());
  }

  private async init(): Promise<void> {
    if (!this.roomUrl) { this.error.set('This class has no room yet.'); this.connecting.set(false); return; }
    try {
      const DailyIframe = (await import('@daily-co/daily-js')).default;
      this.frame = DailyIframe.createFrame(this.container.nativeElement, {
        showLeaveButton: true,
        showFullscreenButton: true,
        iframeStyle: {position: 'absolute', top: '0', left: '0', width: '100%', height: '100%', border: '0'},
      });
      this.frame.on('left-meeting', () => this.leave());
      this.frame.on('error', (e: any) => this.error.set(e?.errorMsg || 'The call ran into a problem.'));

      const opts: any = {url: this.roomUrl};
      if (this.token) opts.token = this.token;
      await this.frame.join(opts);
      this.connecting.set(false);
    } catch (e: any) {
      this.error.set(e?.message || 'Could not connect to the class.');
      this.connecting.set(false);
    }
  }

  leave(): void {
    this.destroyFrame();
    this.closed.emit();
  }

  private destroyFrame(): void {
    if (this.frame) {
      try { this.frame.destroy(); } catch {}
      this.frame = null;
    }
  }

  ngOnDestroy(): void {
    this.destroyFrame();
  }
}
