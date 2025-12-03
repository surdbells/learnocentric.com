import { Component, effect, ElementRef, EventEmitter, input, OnInit, Output, signal, ViewChild } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { IInputOption, LearnoSelect } from '../../../common/learno-select/learno-select';
import { ApiService } from '../../../common/service/api.service';
import { ToastrService } from 'ngx-toastr';
import { LearnoInput } from '../../../common/learno-input/learno-input';
import { LearnoButton } from '../../../common/learno-button/learno-button';
import { Router } from '@angular/router';

@Component({
  selector: 'app-institution-form',
  imports: [ReactiveFormsModule, LearnoInput, LearnoSelect, LearnoButton],
  templateUrl: './institution-form.html',
  styleUrl: './institution-form.css'
})
export class InstitutionForm implements OnInit {

  isLoading = signal(false);
  logoUploading = signal(false);
  logoPreviewUrl = signal<string | null>(null);
  packageOptions = signal<IInputOption[]>([]);
  action = input<string>('Add')
  select = input<{ [key:string]: string }>({})
  isEdit = input<boolean>(false);

  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;




  form = new FormGroup({
    institutionName: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    institutionType: new FormControl<'school'|'tutoring_academy'>('school', { nonNullable: true, validators: [Validators.required] }),
    adminEmail: new FormControl('', { nonNullable: true, validators: [Validators.required, Validators.email] }),
    adminPassword: new FormControl('', { nonNullable: true, validators: [Validators.required, Validators.minLength(6)] }),
    adminFirstName: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    adminLastName: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    address: new FormControl('', { nonNullable: true }),
    phone: new FormControl('', { nonNullable: true }),
    logoUrl: new FormControl('', { nonNullable: true }),
    primaryColor: new FormControl('', { nonNullable: true }),
    packageIds: new FormControl<string[]>([])
  });

    typeOptions: IInputOption[] = [
    { value: 'school', label: 'School' },
    { value: 'tutoring_academy', label: 'Academy' }
  ];

  private findOptionValueByLabel(options: any[], label?: string): string | undefined {
    if (!label) return undefined;
    const match = (options || []).find((o: any) => (o?.label || '').toString().toLowerCase() === label.toString().toLowerCase());
    return match?.value;
  }
  constructor(
    private readonly apiSrv: ApiService,
    private readonly toastService: ToastrService,
    private readonly router: Router
  ) {

    effect(() => {
      const s: any = this.select();
      const hasSelection = !!s && Object.keys(s || {}).length > 0;
        // this.isEdit.set(true);

      
      if (!hasSelection) {
        return;
      }
      const rawType = s['type'] || s['institutionType'] || '';
      const normalizedType = (() => {
        const t = String(rawType || '').toLowerCase();
        if (t === 'academy' || t.includes('tutor')) return 'tutoring_academy';
        if (t === 'tutoring_academy') return 'tutoring_academy';
        return 'school';
      })();
      const institutionType = (normalizedType || this.findOptionValueByLabel(this.typeOptions, rawType)) || '';

      this.form.patchValue({
        institutionName: s['name'],
        institutionType: institutionType as 'school'|'tutoring_academy',
        adminEmail: s['email'],
        phone: s['phone'],
        logoUrl: s['logo_url'],
        primaryColor: s['primary_color'],
        // secondaryColor: s['secondary'],
        address: s['address'],
        adminFirstName: s['admins'][0]['first_name'],
        adminLastName: s['admins'][0]['last_name'],
        // logoUrl: s['logo_url'],
      })

      this.logoPreviewUrl.set(s['logo_url']);

      const pkgIds: string[] = (() => {
        const byIds = Array.isArray(s['packageIds']) ? s['packageIds'] : null;
        const byPackages = Array.isArray(s['packages']) ? s['packages'] : null;
        const byContentPkgs = Array.isArray(s['contentPackages']) ? s['contentPackages'] : null;
        const byContents = Array.isArray(s['contents']) ? s['contents'] : null;
        let ids: any[] = [];
        if (byIds) ids = byIds;
        else if (byPackages) ids = byPackages.map((p: any) => p?.id ?? p?.packageId ?? p?.package_id);
        else if (byContentPkgs) ids = byContentPkgs.map((p: any) => p?.id ?? p?.packageId ?? p?.package_id);
        else if (byContents) ids = byContents.map((c: any) => c?.packageId ?? c?.id);
        return (ids || []).filter((v: any) => v !== undefined && v !== null).map((v: any) => String(v));
      })();
      if (pkgIds.length) {
        this.form.patchValue({ packageIds: pkgIds });
      }
    })
  }

  ngOnInit(): void {
    this.apiSrv.get('/backend/content/packages').subscribe({
      next: (items: any[]) => {
        const opts = (Array.isArray(items) ? items : []).map((p: any) => ({
          value: String(p?.id ?? ''),
          label: String(p?.name ?? ''),
        })).filter(o => o.value && o.label);
        this.packageOptions.set(opts);
        const currentIds = Array.isArray(this.form.value.packageIds) ? this.form.value.packageIds : [];
        if (currentIds.length) {
          this.form.patchValue({ packageIds: currentIds });
        }
      },
      error: (err) => {
        console.error(err);
        this.toastService.error('Failed to load packages');
      }
    });
  }

  onSubmit() {
    if (this.isLoading()) return;
    if (this.logoUploading()) {
      this.toastService.warning('Please wait for logo upload to finish');
      return;
    }
    if (this.form.invalid) {
      this.toastService.error('Please fill in all required fields correctly');
      return;
    }
    const val = this.form.value;
    const payload: any = {
      institutionName: val.institutionName,
      institutionType: val.institutionType,
      adminEmail: val.adminEmail,
      adminPassword: val.adminPassword,
      adminFirstName: val.adminFirstName,
      adminLastName: val.adminLastName,
      address: val.address,
      phone: val.phone,
      logoUrl: val.logoUrl,
      primaryColor: val.primaryColor,
      packageIds: Array.isArray(val.packageIds)
        ? val.packageIds.map(s => Number(s)).filter(n => !Number.isNaN(n))
        : String(val.packageIds || '').toString().split(',').map(s => s.trim()).filter(Boolean).map(s => Number(s)).filter(n => !Number.isNaN(n))
    };

    this.isLoading.set(true);
    this.apiSrv.post('/backend/admin/onboard', payload)
      .subscribe({
        next: (res) => {
          this.toastService.success('Institution onboarded successfully');
          this.form.reset();
          this.router.navigate(['/super-admin/management/institutions']);
        },
        error: (err) => {
          console.error(err);
          this.toastService.error(err?.message ?? 'Failed to onboard institution');
          this.isLoading.set(false);
        },
        complete: () => this.isLoading.set(false)
      });
    
  }

  onLogoFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    const prev = this.logoPreviewUrl();
    if (prev) URL.revokeObjectURL(prev);
    const url = URL.createObjectURL(file);
    this.logoPreviewUrl.set(url);
    const fd = new FormData();
    fd.append('file', file);
    this.logoUploading.set(true);
    this.apiSrv.post('/backend/upload', fd)
      .subscribe({
        next: (res: any) => {
          const uploadUrl = res?.fileUrl ?? res?.data?.url ?? res?.location ?? res?.path ?? '';
          if (uploadUrl) {
            this.form.get('logoUrl')?.setValue(uploadUrl as any);
            this.toastService.success('Logo uploaded');
          } else {
            this.toastService.warning('Uploaded, but URL not returned');
          }
          this.logoUploading.set(false);
        },
        error: (err) => {
          console.error(err);
          this.toastService.error('Failed to upload logo');
          this.logoUploading.set(false);
        }
      });
  }


  onEdit() {
    this.isLoading.set(true);
    this.apiSrv.put(`/backend/admin/institutions/${this.select()['id']}`, { ...this.form.value, id: this.select()['id'] })
      .subscribe({
        next: (res) => {
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
