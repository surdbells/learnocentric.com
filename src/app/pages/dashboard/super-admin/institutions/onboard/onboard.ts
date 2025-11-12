import {Component, OnInit, signal} from '@angular/core';
import {ReactiveFormsModule, FormGroup, FormControl, Validators} from '@angular/forms';
import {LearnoInput} from '../../../../../common/learno-input/learno-input';
import {LearnoSelect, IInputOption} from '../../../../../common/learno-select/learno-select';
import {LearnoButton} from '../../../../../common/learno-button/learno-button';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ApiService} from '../../../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {Router} from '@angular/router';

@Component({
  selector: 'app-super-admin-onboard',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoSelect, LearnoButton, PageHeader],
  templateUrl: './onboard.html',
  styleUrl: './onboard.css'
})
export class SuperAdminOnboard implements OnInit {
  isLoading = signal(false);
  form = new FormGroup({
    institutionName: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    institutionType: new FormControl<'school'|'academy'>('school', { nonNullable: true, validators: [Validators.required] }),
    adminEmail: new FormControl('', { nonNullable: true, validators: [Validators.required, Validators.email] }),
    adminPassword: new FormControl('', { nonNullable: true, validators: [Validators.required, Validators.minLength(6)] }),
    adminFirstName: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    adminLastName: new FormControl('', { nonNullable: true, validators: [Validators.required] }),
    address: new FormControl('', { nonNullable: true }),
    phone: new FormControl('', { nonNullable: true }),
    logoUrl: new FormControl('', { nonNullable: true }),
    primaryColor: new FormControl('', { nonNullable: true }),
    packageIds: new FormControl<string>('')
  });

  typeOptions: IInputOption[] = [
    { value: 'school', label: 'School' },
    { value: 'academy', label: 'Academy' }
  ];

  constructor(
    private readonly apiSrv: ApiService,
    private readonly toastService: ToastrService,
    private readonly router: Router,
  ) {}

  ngOnInit(): void {}

  onSubmit() {
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
      packageIds: (val.packageIds || '').toString().split(',').map(s => s.trim()).filter(Boolean).map(s => Number(s)).filter(n => !Number.isNaN(n))
    };

    this.isLoading.set(true);
    this.apiSrv.post('/backend/admin/onboard', payload)
      .subscribe({
        next: () => {
          this.toastService.success('Institution onboarded successfully');
          this.router.navigate(['/super-admin/institutions']);
        },
        error: (err) => {
          console.error(err);
          this.toastService.error('Failed to onboard institution');
        },
        complete: () => this.isLoading.set(false)
      });
  }
}