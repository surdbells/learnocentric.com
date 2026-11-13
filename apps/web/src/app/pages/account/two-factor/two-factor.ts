import {Component, inject, signal} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {AuthStore} from '../../../common/auth/auth.store';
import {Icon} from '../../../common/icon/icon';
import {portalFor} from '../../../common/auth/portal';

interface TwoFactorState {
  enabled: boolean;
  enabled_at: string | null;
  backup_codes_left: number;
  required: boolean;
  backup_codes?: string[];
}

/**
 * Account → Two-factor authentication (TOTP). Enrol with an authenticator app
 * (QR code or setup key), confirm with a 6-digit code, keep one-time backup
 * codes, regenerate them, or switch 2FA off (not allowed while the platform
 * requires it for staff). Also the landing page when enrolment is enforced.
 */
@Component({
  selector: 'app-two-factor',
  standalone: true,
  imports: [FormsModule, RouterLink, Icon],
  templateUrl: './two-factor.html',
  styleUrl: './two-factor.css',
})
export class TwoFactor {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly auth = inject(AuthService);
  private readonly store = inject(AuthStore);
  private readonly router = inject(Router);

  readonly home = portalFor(this.auth.getAuthSession()?.user?.role) ?? '/';

  loading = signal(true);
  busy = signal(false);
  state = signal<TwoFactorState | null>(null);

  // enrolment
  qr = signal<string | null>(null);
  secret = signal<string | null>(null);
  code = signal('');
  // shown once after enabling / regenerating
  backupCodes = signal<string[]>([]);
  // management
  password = signal('');
  regenCode = signal('');

  constructor() {
    this.load();
  }

  load(): void {
    this.api.get<TwoFactorState>('/backend/auth/2fa').subscribe({
      next: (s) => { this.state.set(s); this.loading.set(false); },
      error: () => { this.loading.set(false); this.toast.error('Could not load your security settings'); },
    });
  }

  startSetup(): void {
    this.busy.set(true);
    this.api.post<{secret: string; otpauth_uri: string}>('/backend/auth/2fa/setup', {}).subscribe({
      next: async (r) => {
        this.secret.set(r.secret);
        try {
          const QR = await import('qrcode');
          this.qr.set(await QR.toDataURL(r.otpauth_uri, {margin: 1, width: 220, color: {dark: '#14532d', light: '#ffffff'}}));
        } catch {
          this.qr.set(null); // the setup key below still works
        }
        this.busy.set(false);
      },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'Could not start set-up'); },
    });
  }

  enable(): void {
    if (!/^\d{6}$/.test(this.code().trim())) { this.toast.error('Enter the 6-digit code from your app'); return; }
    this.busy.set(true);
    this.api.post<TwoFactorState>('/backend/auth/2fa/enable', {code: this.code().trim()}).subscribe({
      next: (s) => {
        this.state.set(s);
        this.backupCodes.set(s.backup_codes ?? []);
        this.secret.set(null); this.qr.set(null); this.code.set('');
        this.markUser(true);
        this.busy.set(false);
        this.toast.success('Two-factor authentication is on');
      },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'That code did not match'); },
    });
  }

  regenerate(): void {
    if (!/^\d{6}$/.test(this.regenCode().trim())) { this.toast.error('Enter the current 6-digit code'); return; }
    this.busy.set(true);
    this.api.post<TwoFactorState>('/backend/auth/2fa/backup-codes', {code: this.regenCode().trim()}).subscribe({
      next: (s) => { this.state.set(s); this.backupCodes.set(s.backup_codes ?? []); this.regenCode.set(''); this.busy.set(false); this.toast.success('New backup codes issued; the old ones no longer work'); },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'Could not issue new codes'); },
    });
  }

  disable(): void {
    if (!this.password()) { this.toast.error('Enter your password'); return; }
    this.busy.set(true);
    this.api.post<TwoFactorState>('/backend/auth/2fa/disable', {password: this.password()}).subscribe({
      next: (s) => { this.state.set(s); this.password.set(''); this.backupCodes.set([]); this.markUser(false); this.busy.set(false); this.toast.success('Two-factor authentication is off'); },
      error: (e) => { this.busy.set(false); this.toast.error(e?.error?.error || 'Could not turn it off'); },
    });
  }

  downloadCodes(): void {
    const text = "LearnoCentric backup codes\nEach code works once.\n\n" + this.backupCodes().join('\n') + '\n';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], {type: 'text/plain'}));
    a.download = 'learnocentric-backup-codes.txt';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  continue(): void {
    this.backupCodes.set([]);
    this.router.navigateByUrl(this.home);
  }

  signOut(): void {
    this.auth.logoutLocal();
    this.router.navigateByUrl('/login');
  }

  /** Keep the cached session user in step so other screens show the right status. */
  private markUser(enabled: boolean): void {
    const s = this.auth.getAuthSession();
    if (s?.user) this.store.persist(s.token, {...s.user, two_factor_enabled: enabled});
  }
}
