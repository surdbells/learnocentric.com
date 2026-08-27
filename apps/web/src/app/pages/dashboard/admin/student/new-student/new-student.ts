import {Component, computed, inject, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../../common/icon/icon';
import {FileUpload, UploadedFile} from '../../../../../common/file-upload/file-upload';
import {ApiService} from '../../../../../common/service/api.service';
import {AuthService} from '../../../../../common/auth/auth.service';

const DRAFT_KEY = 'add_learner_draft';

/**
 * Sectioned "Add Learner" onboarding form (design: Classes & Learners II_SA) -
 * six sections (Profile / Academic Placement / Login / Guardian / Support /
 * Consent) with an enrolment-summary rail, a live progress checklist, and Save
 * Draft. Posts to /school/learners which creates the account, sets the
 * modelled fields and stores the guardian/support/consent onboarding blob.
 */
@Component({
  selector: 'app-new-student',
  standalone: true,
  imports: [PageHeader, Icon, ReactiveFormsModule, RouterLink, FileUpload],
  templateUrl: './new-student.html',
  styleUrl: './new-student.css',
})
export class NewStudent {
  private fb = inject(FormBuilder);
  private api = inject(ApiService);
  private toast = inject(ToastrService);
  private auth = inject(AuthService);
  private router = inject(Router);

  saving = signal(false);
  classes = signal<any[]>([]);
  photoUrl = signal<string | null>(null);
  // A signal mirror of the form value so computed checklist/summary react to edits.
  private tick = signal(0);

  readonly schoolName = signal((this.auth.getAuthSession()?.user as any)?.institutionName ?? 'Your school');
  private readonly root = this.auth.getAuthSession()?.user?.role === 'tutor_admin' ? '/academy' : '/admin';
  readonly backLink = this.root + '/students';

  form = this.fb.group({
    firstName: ['', Validators.required],
    lastName: ['', Validators.required],
    gender: [''],
    date_of_birth: [''],
    admission_number: [''],
    class_id: [null as number | null],
    arm: [''],
    admission_date: [''],
    enrollment_status: ['pending'],
    previous_school: [''],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(6)]],
    guardian: this.fb.group({name: [''], relationship: [''], phone: [''], email: [''], whatsapp: [''], address: [''], emergency: [''], has_account: [false]}),
    support: this.fb.group({medical: [''], special_needs: [''], transport: [''], remarks: ['']}),
    consent: this.fb.group({parent: [false], media: [false], data_privacy: [false], comms_preference: ['']}),
  });

  constructor() {
    this.form.valueChanges.subscribe(() => this.tick.update(v => v + 1));
    this.api.get<any>('/backend/school/classes').subscribe({next: (r) => this.classes.set(Array.isArray(r) ? r : (r?.data ?? []))});
    const draft = localStorage.getItem(DRAFT_KEY);
    if (draft) { try { this.form.patchValue(JSON.parse(draft)); } catch {} }
  }

  onPhoto(f: UploadedFile): void { this.photoUrl.set(f.url); }

  readonly selectedClass = computed(() => {
    this.tick();
    const id = this.form.get('class_id')!.value;
    return this.classes().find((c: any) => c.id === id) ?? null;
  });

  /** Per-section completeness for the progress checklist. */
  readonly checklist = computed(() => {
    this.tick();
    const v = this.form.getRawValue();
    const g = v.guardian, c = v.consent;
    return [
      {label: 'Learner Profile', done: !!(v.firstName && v.lastName)},
      {label: 'Academic Placement', done: !!v.class_id},
      {label: 'Login & Account', done: !!(v.email && (v.password ?? '').length >= 6)},
      {label: 'Guardian Information', done: !!(g.name && g.phone)},
      {label: 'Support Notes', done: !!(v.support.transport || v.support.medical || v.support.remarks)},
      {label: 'Consent & Safeguarding', done: !!(c.parent && c.data_privacy)},
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
    const body = {...v, profile_image_url: this.photoUrl(), class_id: v.class_id || null};
    this.api.post<any>('/backend/school/learners', body).subscribe({
      next: () => {
        this.toast.success('Learner created');
        localStorage.removeItem(DRAFT_KEY);
        this.saving.set(false);
        this.router.navigateByUrl(this.root + '/academics/classes-learners');
      },
      error: (e) => { this.toast.error(e?.error?.error || 'Could not create the learner'); this.saving.set(false); },
    });
  }
}
