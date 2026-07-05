import {Component, computed, inject, input, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {FileUpload, UploadedFile} from '../../../common/file-upload/file-upload';
import {ApiService} from '../../../common/service/api.service';

/**
 * School / academy profile editor — loads the signed-in admin's own institution
 * and saves name, type, address, brand colour, logo and primary contact.
 */
@Component({
  selector: 'app-school-profile-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoButton, FileUpload],
  templateUrl: './school-profile-form.html',
  styleUrl: './school-profile-form.css',
})
export class SchoolProfileForm {
  role = input<string>('');

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  saving = signal(false);
  logoUrl = signal<string | null>(null);

  readonly noun = computed(() => (this.role() === 'tutor_admin' ? 'academy' : 'school'));

  form = new FormGroup({
    name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    type: new FormControl('school', {nonNullable: true}),
    address: new FormControl(''),
    brandColor: new FormControl('#39c645', {nonNullable: true}),
    contactName: new FormControl(''),
    contactEmail: new FormControl('', {validators: [Validators.email]}),
    contactPhone: new FormControl(''),
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.get<any>('/backend/school/profile').subscribe({
      next: (inst) => {
        this.logoUrl.set(inst?.logo_url ?? null);
        this.form.patchValue({
          name: inst?.name ?? '',
          type: inst?.type ?? 'school',
          address: inst?.address ?? '',
          brandColor: inst?.branding?.color ?? '#39c645',
          contactName: inst?.admin_contact?.name ?? '',
          contactEmail: inst?.admin_contact?.email ?? '',
          contactPhone: inst?.admin_contact?.phone ?? '',
        });
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load the profile'); },
    });
  }

  onLogoUploaded(file: UploadedFile): void { this.logoUrl.set(file.url); }
  onLogoCleared(): void { this.logoUrl.set(null); }

  onSubmit(): void {
    if (this.form.get('name')!.invalid) { this.toast.error('A name is required'); return; }
    if (this.form.get('contactEmail')!.invalid) { this.toast.error('The contact email looks invalid'); return; }
    const v = this.form.getRawValue();
    const body = {
      name: v.name,
      type: v.type,
      address: v.address || null,
      logo_url: this.logoUrl(),
      brand_color: v.brandColor || null,
      admin_contact: {name: v.contactName || '', email: v.contactEmail || '', phone: v.contactPhone || ''},
    };
    this.saving.set(true);
    this.api.put('/backend/school/profile', body).subscribe({
      next: () => { this.toast.success('Profile saved'); this.saving.set(false); },
      error: (e) => { this.toast.error(e?.error?.error || 'Save failed'); this.saving.set(false); },
    });
  }
}
