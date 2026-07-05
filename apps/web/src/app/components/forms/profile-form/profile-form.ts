import {Component, ElementRef, OnInit, signal, ViewChild} from '@angular/core';
import {LearnoButton} from "../../../common/learno-button/learno-button";
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {IInputOption} from '../../../common/learno-select/learno-select';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {SkeletonLoader} from '../../../common/skeleton-loader/skeleton-loader';

@Component({
  selector: 'app-profile-form',
  imports: [
    LearnoButton,
    LearnoInput,
    ReactiveFormsModule,
    SkeletonLoader
  ],
  templateUrl: './profile-form.html',
  styleUrl: './profile-form.css'
})
export class ProfileForm implements OnInit{


  form = new FormGroup({
    _email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
    firstName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    address: new FormControl(''),
    phone: new FormControl(''),
    lastName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    dateOfBirth: new FormControl(''),
    profileImageUrl: new FormControl('', {nonNullable: true}),
    // institutionId: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
  })
  isLoading = signal<boolean>(false);
  imageUploading = signal<boolean>(false);
  passportPreviewUrl = signal<string | null>(null);
  isDragOver = signal<boolean>(false);

  @ViewChild('passportFile', { static: false }) passportFileInput!: ElementRef<HTMLInputElement>;

  fullName = signal<string>('');
  roleLabel = signal<string>('');
  initials = signal<string>('');

  constructor(
    private toastService: ToastrService,
    private apiSrv: ApiService,
    private authSrv: AuthService
  ) {
    const u = this.authSrv.getAuthSession()?.user;
    const name = [u?.firstName, u?.lastName].filter(Boolean).join(' ') || (u?.email ?? 'Your profile');
    this.fullName.set(name);
    this.initials.set(name.split(' ').map((p: string) => p[0]).slice(0, 2).join('').toUpperCase());
    const roles: Record<string, string> = {school_admin: 'School Administrator', tutor_admin: 'Academy Administrator', teacher: 'Teacher', student: 'Student', parent: 'Parent', super_admin: 'Platform Admin'};
    this.roleLabel.set(roles[u?.role ?? ''] ?? '');
  }

  ngOnInit(): void {
    this.isLoading.set(true);
    this.apiSrv.get("/backend/auth/me")
      .subscribe({
        next: (res) => {
          this.form.patchValue({ ...res, _email: res?.email }, {emitEvent: false});
          const existing = res?.profileImageUrl ?? res?.passportUrl ?? res?.avatarUrl ?? '';
          if (existing) {
            this.passportPreviewUrl.set(String(existing));
            this.form.get('profileImageUrl')?.setValue(String(existing));
          }
          this.isLoading.set(false);
        },
        error: (err) => {
          this.isLoading.set(false);
          this.toastService.error("failed to fetch profile")
          console.log(err)
        },
        complete: () => {}
      })
  }

  onSubmit() {

    this.isLoading.set(true);

    const {user} = this.authSrv.getAuthSession();
    if(!user) {
      this.isLoading.set(false);
      this.toastService.error("failed to submit, login first")
      return;
    }

    // console.log(this.form.value, user)
    if (this.form.valid) {
      this.apiSrv.put("/backend/auth/profile", {
        ...this.form.value,
      }).subscribe(
        {
          next: (res) => {
            this.toastService.success("updated successfully")
          },
          error: (err) => {
            this.isLoading.set(false);
            this.toastService.error("failed to update")
            console.log(err)
          },
          complete: () => {
            this.isLoading.set(false);
          }
        }
      )

    } else {
      this.isLoading.set(false);
      const err = Object.keys(this.form.controls).reduce((acc: any, key) => {
        const control = this.form.get(key);
        if (control?.errors) {
          acc[key] = control.errors;
        }
        return acc;
      }, {});
      console.log(err);
      this.toastService.error("Unable to update your profile")
    }
  }

  onPassportFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    const prev = this.passportPreviewUrl();
    if (prev) URL.revokeObjectURL(prev);
    const url = URL.createObjectURL(file);
    this.passportPreviewUrl.set(url);
    const fd = new FormData();
    fd.append('file', file);
    this.imageUploading.set(true);
    this.apiSrv.post('/backend/upload', fd)
      .subscribe({
        next: (res: any) => {
          const uploadUrl = res?.url ?? res?.fileUrl ?? res?.data?.url ?? res?.location ?? res?.path ?? '';
          if (uploadUrl) {
            this.form.get('profileImageUrl')?.setValue(uploadUrl as any);
            this.toastService.success('Passport uploaded');
          } else {
            this.toastService.warning('Uploaded, but URL not returned');
          }
          this.imageUploading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastService.error('Failed to upload passport');
          this.imageUploading.set(false);
        }
      });
  }

  onDragOver(event: DragEvent) {
    event.preventDefault();
    this.isDragOver.set(true);
  }

  onDragLeave(event: DragEvent) {
    event.preventDefault();
    this.isDragOver.set(false);
  }

  onDropPassport(event: DragEvent) {
    event.preventDefault();
    this.isDragOver.set(false);
    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    const prev = this.passportPreviewUrl();
    if (prev) URL.revokeObjectURL(prev);
    const url = URL.createObjectURL(file);
    this.passportPreviewUrl.set(url);
    const fd = new FormData();
    fd.append('file', file);
    this.imageUploading.set(true);
    this.apiSrv.post('/backend/upload', fd)
      .subscribe({
        next: (res: any) => {
          const uploadUrl = res?.url ?? res?.fileUrl ?? res?.data?.url ?? res?.location ?? res?.path ?? '';
          if (uploadUrl) {
            this.form.get('profileImageUrl')?.setValue(uploadUrl as any);
            this.toastService.success('Passport uploaded');
          } else {
            this.toastService.warning('Uploaded, but URL not returned');
          }
          this.imageUploading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastService.error('Failed to upload passport');
          this.imageUploading.set(false);
        }
      });
  }
}
