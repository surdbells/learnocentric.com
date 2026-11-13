import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {StudentGuardians} from '../../admin/student-guardians/student-guardians';
import {OnboardingRecord} from '../../admin/onboarding-record/onboarding-record';

@Component({
  selector: 'app-edit-student-form',
  imports: [
    FormsModule,
    LearnoInput,
    ReactiveFormsModule,
    LearnoButton,
    StudentGuardians,
    OnboardingRecord
  ],
  templateUrl: './edit-student-form.html',
  styleUrl: './edit-student-form.css'
})
export class EditStudentForm {

  isLoading = signal(false);
  select = input<{ [key:string]: string }>({})
  /** Show the Parents & guardians panel (learners only; this form is reused for staff). */
  guardians = input(false);

  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;


  constructor(
    private toastService: ToastrService,
    private readonly apiSrv: ApiService,
  ) {
    effect(() => {
      const s = this.select();
      if (!s || !s['id']) { this.form.reset(); return; }

      this.form.reset();
      this.form.patchValue({
        email: s['email'] ?? '',
        firstName: s['first_name'] ?? '',
        lastName: s['last_name'] ?? '',
        dateOfBirth: (s['date_of_birth'] as any) ?? null,
        phone: (s['phone'] as any) ?? null,
        isActive: (s['is_active'] as any) ?? false,
      }, { emitEvent: false });
    });
  }

  form = new FormGroup({
    email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
    firstName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    lastName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    dateOfBirth: new FormControl(null),
    phone: new FormControl(null),
    isActive: new FormControl<boolean>(false),
    // institutionId: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
  })


  tempPassword = signal('');
  settingPassword = signal(false);

  /** Admin sets a temporary password for someone who can't sign in. */
  setPassword(): void {
    if (!this.tempPassword()) { this.toastService.error('Enter a temporary password'); return; }
    this.settingPassword.set(true);
    this.apiSrv.post<any>(`/backend/auth/user-profile/${this.select()['id']}/password`, {password: this.tempPassword()}).subscribe({
      next: (r) => { this.settingPassword.set(false); this.tempPassword.set(''); this.toastService.success(r?.message ?? 'Temporary password set'); },
      error: (e) => { this.settingPassword.set(false); this.toastService.error(e?.error?.error || 'Could not set the password'); },
    });
  }

  onEdit() {
    this.isLoading.set(true);
    this.apiSrv.put("/backend/auth/user-profile/" + this.select()['id'], { ...this.form.value, id: this.select()['id'] })
      .subscribe({
        next: () => {
          this.form.reset();
          this.toastService.success("updated successfully")
          this.submitted.emit({ success: true });
        },
        error: (err) => {
          this.isLoading.set(false);
          this.toastService.error("failed to submit")
          console.log(err)
        },
        complete: () => {
          this.isLoading.set(false);
          this.autoCloseBtn.nativeElement.click();
        }
      })
  }

}
