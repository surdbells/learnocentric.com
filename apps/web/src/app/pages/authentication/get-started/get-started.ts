import {Component, computed, inject, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {AuthService} from '../../../common/auth/auth.service';
import {ModuleAccessService} from '../../../common/auth/module-access.service';
import {roleRoot} from '../../../common/auth/portal';
import {ApiService} from '../../../common/service/api.service';
import {LearnoButton} from '../../../common/learno-button/learno-button';

/**
 * Self onboarding: a school or academy creates its own account on the website.
 *
 * The organisation pays before service begins, so after signing up the
 * administrator lands on Billing to choose a plan; everything else opens once
 * the payment is verified. When the platform asks for email confirmation, the
 * administrator confirms the address and then signs in.
 */
@Component({
  selector: 'app-get-started',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoButton, RouterLink],
  templateUrl: './get-started.html',
  styleUrl: '../sign-in/sign-in.css',
})
export class GetStarted {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly router = inject(Router);
  private readonly authService = inject(AuthService);
  private readonly moduleAccess = inject(ModuleAccessService);

  loading = signal(true);
  isLoading = signal(false);
  /** What the platform currently allows: {open, school, academy, password_min_length}. */
  options = signal<any | null>(null);
  /** Set when the account exists but its email must be confirmed before signing in. */
  pendingEmail = signal<string | null>(null);

  readonly kinds = computed(() => {
    const o = this.options();
    return [
      ...(o?.school ? [{value: 'school', label: 'School'}] : []),
      ...(o?.academy ? [{value: 'academy', label: 'Academy'}] : []),
    ];
  });
  readonly minLength = computed(() => this.options()?.password_min_length ?? 8);
  readonly noun = computed(() => this.form.controls.type.value === 'academy' ? 'academy' : 'school');

  form = new FormGroup({
    type: new FormControl<'school' | 'academy'>('school', {nonNullable: true}),
    organisationName: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.minLength(3)]}),
    firstName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    lastName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
    phone: new FormControl('', {nonNullable: true}),
    password: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    confirm: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    /** Honeypot: hidden from people, so only bots fill it in. */
    website: new FormControl('', {nonNullable: true}),
  });

  constructor() {
    this.api.get<any>('/backend/auth/signup-options').subscribe({
      next: (o) => {
        this.options.set(o);
        if (!o?.school && o?.academy) this.form.controls.type.setValue('academy');
        this.loading.set(false);
      },
      error: () => { this.options.set({open: false}); this.loading.set(false); },
    });
  }

  submit(): void {
    const v = this.form.getRawValue();
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.toast.error(`Please complete every required field.`);
      return;
    }
    if (v.password.length < this.minLength()) {
      this.toast.error(`Choose a password of at least ${this.minLength()} characters.`);
      return;
    }
    if (v.password !== v.confirm) {
      this.toast.error('The two passwords do not match.');
      return;
    }
    this.isLoading.set(true);
    this.api.post<any>('/backend/auth/signup-organisation', {
      type: v.type,
      organisation_name: v.organisationName.trim(),
      first_name: v.firstName.trim(),
      last_name: v.lastName.trim(),
      email: v.email.trim(),
      phone: v.phone.trim(),
      password: v.password,
      website: v.website,
    }).subscribe({
      next: (res) => {
        this.isLoading.set(false);
        if (res?.verification_required) {
          this.pendingEmail.set(res.email ?? v.email);
          return;
        }
        this.authService.persistLogin(res);
        this.moduleAccess.refresh();
        this.toast.success('Your account is ready. Choose a plan to open it.');
        this.router.navigateByUrl(roleRoot(res?.user?.role) + '/management/billing');
      },
      error: (e) => {
        this.isLoading.set(false);
        this.toast.error(e?.error?.error || 'We could not create the account. Please try again.', undefined, {timeOut: 10000});
      },
    });
  }
}
