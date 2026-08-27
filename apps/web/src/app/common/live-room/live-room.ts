import {afterNextRender, Component, EventEmitter, inject, Input, OnDestroy, Output, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {AuthService} from '../auth/auth.service';
import {Icon} from '../icon/icon';

interface RemoteTile { uid: string | number; hasVideo: boolean; }

/**
 * Agora live-class call. Agora has no embeddable prebuilt UI, so this component
 * IS the call: it joins the channel with the server-issued RTC token, publishes
 * the local camera/mic, subscribes to remote participants and renders the video
 * grid plus mic / camera / screen-share / leave controls.
 *
 * agora-rtc-sdk-ng is browser-only, so it is dynamically imported after the first
 * render (SSR-safe), mirroring how other browser libraries are loaded.
 */
@Component({
  selector: 'app-live-room',
  standalone: true,
  imports: [Icon, RouterLink],
  templateUrl: './live-room.html',
  styleUrl: './live-room.css',
})
export class LiveRoom implements OnDestroy {
  @Input({required: true}) appId!: string;
  @Input({required: true}) channel!: string;
  @Input() token: string | null = null;
  @Input() uid: number = 0;
  @Input() title = 'Live class';
  @Input() isHost = false;
  @Output() closed = new EventEmitter<void>();

  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);

  /** The learner "report a concern" affordance is student-only (its route is under /student). */
  protected readonly isStudent = this.auth.getAuthSession()?.user?.role === 'student';

  connecting = signal(true);
  error = signal<string | null>(null);
  micOn = signal(false);
  camOn = signal(false);
  sharing = signal(false);
  remotes = signal<RemoteTile[]>([]);

  private AgoraRTC: any = null;
  private client: any = null;
  private localAudio: any = null;
  private localVideo: any = null;
  private screenTrack: any = null;
  private readonly remoteMap = new Map<string | number, any>();

  constructor() {
    afterNextRender(() => this.init());
  }

  private async init(): Promise<void> {
    if (!this.appId || !this.channel) {
      this.error.set('This class has no channel yet.');
      this.connecting.set(false);
      return;
    }
    try {
      const mod: any = await import('agora-rtc-sdk-ng');
      const AgoraRTC = mod.default ?? mod;
      this.AgoraRTC = AgoraRTC;
      AgoraRTC.setLogLevel?.(3); // warnings + errors only

      const client = AgoraRTC.createClient({mode: 'rtc', codec: 'vp8'});
      this.client = client;
      client.on('user-published', this.onPublished);
      client.on('user-unpublished', this.onUnpublished);
      client.on('user-left', this.onLeft);

      await client.join(this.appId, this.channel, this.token || null, this.uid || null);

      // Publishing is best-effort: a learner whose camera/mic is blocked still
      // joins to watch and listen.
      try {
        const [mic, cam] = await AgoraRTC.createMicrophoneAndCameraTracks();
        this.localAudio = mic;
        this.localVideo = cam;
        await client.publish([mic, cam]);
        this.micOn.set(true);
        this.camOn.set(true);
        this.playLocal();
      } catch {
        this.toast.info('Joined without camera/mic, allow device access to share.');
      }
      this.connecting.set(false);
    } catch (e: any) {
      this.error.set(e?.message || 'Could not connect to the class.');
      this.connecting.set(false);
    }
  }

  // --- remote participants ---
  private onPublished = async (user: any, mediaType: 'video' | 'audio'): Promise<void> => {
    try { await this.client.subscribe(user, mediaType); } catch { return; }
    this.remoteMap.set(user.uid, user);
    if (mediaType === 'audio') {
      user.audioTrack?.play();
      this.syncRemotes();
    } else {
      this.syncRemotes();
      this.playRemote(user.uid);
    }
  };

  private onUnpublished = (user: any, mediaType: 'video' | 'audio'): void => {
    if (mediaType === 'video') { this.syncRemotes(); }
  };

  private onLeft = (user: any): void => {
    this.remoteMap.delete(user.uid);
    this.syncRemotes();
  };

  /** Rebuild the tile list (a fresh array so the grid re-renders). */
  private syncRemotes(): void {
    this.remotes.set([...this.remoteMap.values()].map((u) => ({uid: u.uid, hasVideo: !!u.videoTrack})));
  }

  private playRemote(uid: string | number): void {
    const play = (tries: number): void => {
      const el = document.getElementById('remote-' + uid);
      const track = this.remoteMap.get(uid)?.videoTrack;
      if (el && track) { track.play(el); }
      else if (tries > 0) { requestAnimationFrame(() => play(tries - 1)); }
    };
    play(30);
  }

  private playLocal(): void {
    const track = this.sharing() ? this.screenTrack : this.localVideo;
    if (!track) return;
    const play = (tries: number): void => {
      const el = document.getElementById('local-player');
      if (el) { track.play(el); }
      else if (tries > 0) { requestAnimationFrame(() => play(tries - 1)); }
    };
    play(30);
  }

  // --- controls ---
  toggleMic(): void {
    if (!this.localAudio) { this.toast.info('No microphone available.'); return; }
    const on = !this.micOn();
    this.localAudio.setEnabled(on);
    this.micOn.set(on);
  }

  toggleCam(): void {
    if (!this.localVideo) { this.toast.info('No camera available.'); return; }
    const on = !this.camOn();
    this.localVideo.setEnabled(on);
    this.camOn.set(on);
    if (on && !this.sharing()) this.playLocal();
  }

  async toggleShare(): Promise<void> {
    if (!this.client) return;
    if (this.sharing()) { await this.stopShare(); return; }
    try {
      const screen = await this.AgoraRTC.createScreenVideoTrack({}, 'disable');
      this.screenTrack = Array.isArray(screen) ? screen[0] : screen;
      if (this.localVideo) { try { await this.client.unpublish(this.localVideo); } catch {} }
      await this.client.publish(this.screenTrack);
      this.screenTrack.on('track-ended', () => this.stopShare());
      this.sharing.set(true);
      this.playLocal();
    } catch {
      this.screenTrack = null;
      this.toast.error('Could not start screen sharing.');
    }
  }

  private async stopShare(): Promise<void> {
    if (this.screenTrack) {
      try { await this.client.unpublish(this.screenTrack); } catch {}
      try { this.screenTrack.stop(); this.screenTrack.close(); } catch {}
      this.screenTrack = null;
    }
    this.sharing.set(false);
    if (this.localVideo && this.camOn()) {
      try { await this.client.publish(this.localVideo); } catch {}
      this.playLocal();
    }
  }

  async leave(): Promise<void> {
    await this.cleanup();
    this.closed.emit();
  }

  private async cleanup(): Promise<void> {
    try { if (this.screenTrack) { this.screenTrack.stop(); this.screenTrack.close(); this.screenTrack = null; } } catch {}
    try { this.localAudio?.stop(); this.localAudio?.close(); } catch {}
    try { this.localVideo?.stop(); this.localVideo?.close(); } catch {}
    try { await this.client?.leave(); } catch {}
    this.localAudio = this.localVideo = this.client = null;
    this.remoteMap.clear();
    this.remotes.set([]);
  }

  ngOnDestroy(): void { void this.cleanup(); }
}
