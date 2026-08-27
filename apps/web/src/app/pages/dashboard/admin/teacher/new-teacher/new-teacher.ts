import {Component, computed, inject, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {FileUpload, UploadedFile} from '../../../../../common/file-upload/file-upload';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';

const DRAFT_KEY = 'add_staff_draft';

/**
 * Sectioned "Add Staff" onboarding form (design: Teachers & Staff II_SA) -
 * Profile / Role & Employment / Teaching Assignment / Login / Compliance, with
 * a staff-summary rail and a progress checklist. Posts to /school/staff which
 * creates the account, sets role + gender, stores the employment/consent
 * onboarding blob and (optionally) a class+subject teaching assignment.
 */
@Component({
  selector: 'app-new-teacher',
  standalone: true,
  imports: [PageHeader, Icon, ReactiveFormsModule, RouterLink, FileUpload],
  templateUrl: './new-teacher.html',
  styleUrl: './new-teacher.css',
})
export class NewTeacher {
  private fb = inject(FormBuilder);
  private api = inject(ApiService);
  private toast = inject(ToastrService);
  private auth = inject(AuthService);
  private router = inject(Router);

  saving = signal(false);
  classes = signal<any[]>([]);
  subjects = signal<any[]>([]);
  photoUrl = signal<string | null>(null);
  private tick = signal(0);

  readonly schoolName = signal((this.auth.getAuthSession()?.user as any)?.institutionName ?? 'Your school');
  private readonly root = this.auth.getAuthSession()?.user?.role === 'tutor_admin' ? '/academy' : '/admin';
  readonly backLink = this.root + '/teachers';
  readonly staffNoun = this.root === '/academy' ? 'Tutor' : 'Staff';

  form = this.fb.group({
    firstName: ['', Validators.required],
    lastName: ['', Validators.required],
    gender: [''],
    phone: [''],
    role: ['teacher'],
    staff_id: [''],
    department: [''],
    employment_type: [''],
    qualification: [''],
    start_date: [''],
    class_id: [null as number | null],
    subject_id: [null as number | null],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(6)]],
    contact: this.fb.group({address: [''], emergency: ['']}),
    consent: this.fb.group({data_privacy: [false], code_of_conduct: [false], safeguarding_trained: [false]}),
  });

  constructor() {
    this.form.valueChanges.subscribe(() => this.tick.update(v => v + 1));
    this.api.get<any>('/backend/school/classes').subscribe({next: (r) => this.classes.set(Array.isArray(r) ? r : (r?.data ?? []))});
    this.api.get<any>('/backend/school/subjects').subscribe({next: (r) => this.subjects.set(Array.isArray(r) ? r : (r?.data ?? []))});
    const draft = localStorage.getItem(DRAFT_KEY);
    if (draft) { try { this.form.patchValue(JSON.parse(draft)); } catch {} }
  }

  onPhoto(f: UploadedFile): void { this.photoUrl.set(f.url); }

  readonly selectedClass = computed(() => { this.tick(); const id = this.form.get('class_id')!.value; return this.classes().find((c: any) => c.id === id) ?? null; });

  readonly checklist = computed(() => {
    this.tick();
    const v = this.form.getRawValue();
    return [
      {label: 'Staff Profile', done: !!(v.firstName && v.lastName)},
      {label: 'Role & Employment', done: !!(v.role && v.employment_type)},
      {label: 'Teaching Assignment', done: !!(v.class_id && v.subject_id)},
      {label: 'Login & Account', done: !!(v.email && (v.password ?? '').length >= 6)},
      {label: 'Compliance', done: !!(v.consent.data_privacy && v.consent.code_of_conduct)},
    ];
  });
  readonly completeCount = computed(() => this.checklist().filter(c => c.done).length);

  saveDraft(): void {
    localStorage.setItem(DRAFT_KEY, JSON.stringify(this.form.getRawValue()));
    this.toast.success('Draft saved on this device');
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.toast.error('Fill in the required fields (name, email, temporary password).');
      return;
    }
    this.saving.set(true);
    const v = this.form.getRawValue();
    const body = {...v, profile_image_url: this.photoUrl(), class_id: v.class_id || null, subject_id: v.subject_id || null};
    this.api.post<any>('/backend/school/staff', body).subscribe({
      next: () => {
        this.toast.success(this.staffNoun + ' created');
        localStorage.removeItem(DRAFT_KEY);
        this.saving.set(false);
        this.router.navigateByUrl(this.root + '/teachers');
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not create the staff member'); this.saving.set(false); },
    });
  }
}
