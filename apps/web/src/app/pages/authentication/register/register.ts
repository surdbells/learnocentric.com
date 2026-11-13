import {Component, inject, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {AuthResponse} from '../../../common/auth/auth.models';
import {AuthService} from '../../../common/auth/auth.service';
import {ModuleAccessService} from '../../../common/auth/module-access.service';
import {portalFor} from '../../../common/auth/portal';
import {ApiService} from '../../../common/service/api.service';
import {LearnoButton} from '../../../common/learno-button/learno-button';

/**
 * Public self sign-up for learners and parents. Requires the join code a school
 * shares when it opens self sign-up; the code decides the school.
 */
@Component({
  selector: 'app-register',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoButton, RouterLink],
  templateUrl: './register.html',
  styleUrl: '../sign-in/sign-in.css',
})
export class Register {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly router = inject(Router);
  private readonly authService = inject(AuthService);
  private readonly moduleAccess = inject(ModuleAccessService);

  isLoading = signal(false);
  /** School the entered code belongs to, once checked. */
  school = signal<string | null>(null);
  codeError = signal<string | null>(null);

  form = new FormGroup({
    joinCode: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.minLength(4)]}),
    role: new FormControl<'student' | 'parent'>('student', {nonNullable: true}),
    firstName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    lastName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
    password: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.minLength(8)]}),
  });

  /** Confirm the join code and show which school it opens. */
  checkCode(): void {
    const code = this.form.controls.joinCode.value.trim().toUpperCase();
    this.school.set(null);
    this.codeError.set(null);
    if (code.length < 4) return;
    this.api.get<any>(`/backend/auth/join/${encodeURIComponent(code)}`).subscribe({
      next: (r) => this.school.set(r?.name ?? null),
      error: (e) => this.codeError.set(e?.error?.error || 'That join code is not valid.'),
    });
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.toast.error('Please complete every field (password: at least 8 characters).');
      return;
    }
    this.isLoading.set(true);
    const v = this.form.getRawValue();
    this.api.post<AuthResponse>('/backend/auth/register', {...v, joinCode: v.joinCode.trim().toUpperCase()}).subscribe({
      next: (res) => {
        this.authService.persistLogin(res);
        this.moduleAccess.refresh();
        this.toast.success('Welcome! Your account is ready.');
        this.router.navigateByUrl(portalFor((res.user as any)?.role) ?? '/');
      },
      error: (e) => {
        this.isLoading.set(false);
        this.toast.error(e?.error?.error || 'Could not create your account.');
      },
    });
  }
}
