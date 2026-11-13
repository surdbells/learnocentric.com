import {Component, signal} from '@angular/core';
import {FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from "ngx-toastr";
import {Router, RouterLink} from "@angular/router";
import {portalFor} from '../../../common/auth/portal';
import {AuthResponse, AuthUser} from '../../../common/auth/auth.models';
import {AuthService} from '../../../common/auth/auth.service';
import {ModuleAccessService} from '../../../common/auth/module-access.service';
import {ApiService} from '../../../common/service/api.service';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-sign-in',
  imports: [
    ReactiveFormsModule,
    FormsModule,
    LearnoButton,
    RouterLink
  ],
  templateUrl: './sign-in.html',
  styleUrl: './sign-in.css'
})
export class SignIn {
  private app = 'learno'
  private auth: AuthResponse | null = null;

  isLoading = signal<boolean>(false)
  /** Set after a correct password when the account has 2FA: the second step. */
  challenge = signal<string | null>(null);
  code = signal('');
  /** Set when sign-in is refused because the email hasn't been confirmed yet. */
  unverifiedEmail = signal<string | null>(null);

    form = new FormGroup({
        email: new FormControl('', [Validators.email, Validators.required] ),
        password: new FormControl('',  [Validators.required] )
    })

    constructor(
        private toast: ToastrService,
        private router: Router,
        private authService: AuthService,
        private moduleAccess: ModuleAccessService,
        private apiService: ApiService,
    ) { }

    login() {

        this.isLoading.set(true)

       if(this.form.invalid) {
         this.toast.error('Invalid email or password');
         this.form.setValue({email: '', password: ''})
       }

      this.apiService.post(`/backend/auth/login`, this.form.value).subscribe(
        {
          next: (val: any) => {
            this.isLoading.set(false)
            if (val?.two_factor_required) {
              // Password OK; ask for the authenticator code.
              this.challenge.set(val.challenge);
              this.code.set('');
              return;
            }
            this.finish(val);
          },
        error: (err) => {
          this.isLoading.set(false)
          if (err?.status === 403 && err?.error?.code === 'email_unverified') {
            // Self-registered account that hasn't opened its confirmation link yet.
            this.unverifiedEmail.set(String(this.form.value.email ?? ''));
            return;
          }
          this.toast.error(err?.status === 401 || !err?.error?.error ? "Incorrect email or password" : err.error.error);
          return;
        },
          complete: () => {
          this.isLoading.set(false)
          }
        }
      );
    }

    resendVerification(): void {
      this.apiService.post<any>('/backend/auth/email/resend', {email: this.unverifiedEmail()}).subscribe({
        next: (r) => this.toast.success(r?.message ?? 'We\'ve sent a new confirmation link.'),
        error: () => this.toast.error('Could not send the link. Please try again shortly.'),
      });
    }

    /** Second step: exchange the challenge + authenticator (or backup) code for a session. */
    verifyCode(): void {
      const code = this.code().trim();
      if (!code) { this.toast.error('Enter the code from your authenticator app'); return; }
      this.isLoading.set(true);
      this.apiService.post<any>('/backend/auth/2fa/verify', {challenge: this.challenge(), code}).subscribe({
        next: (val) => {
          this.isLoading.set(false);
          if (val?.backup_codes_left !== undefined && val.backup_codes_left <= 2 && /-/.test(code)) {
            this.toast.warning(`Only ${val.backup_codes_left} backup codes left. Issue new ones from Two-factor settings.`);
          }
          this.finish(val);
        },
        error: (e) => {
          this.isLoading.set(false);
          if (e?.status === 401 && /expired/i.test(e?.error?.error ?? '')) { this.cancelCode(); }
          this.toast.error(e?.error?.error || 'That code is incorrect');
        },
      });
    }

    cancelCode(): void {
      this.challenge.set(null);
      this.code.set('');
      this.form.reset();
    }

    private finish(val: AuthResponse & {two_factor_setup_required?: boolean}): void {
      this.auth = val;
      this.authService.persistLogin(this.auth);
      this.moduleAccess.refresh(); // load the school's granted feature modules
      this.form.reset();
      this.challenge.set(null);
      this.toast.success('Login Successful');

      if (val.two_factor_setup_required) {
        this.router.navigateByUrl('/account/two-factor');
        return;
      }
      const user = val.user as AuthUser;
      const portal = portalFor(user['role']);
      if (portal) {
        this.router.navigateByUrl(portal);
      } else {
        this.toast.error("Your account has no dashboard assigned. Please contact your administrator.");
      }
    }
}
