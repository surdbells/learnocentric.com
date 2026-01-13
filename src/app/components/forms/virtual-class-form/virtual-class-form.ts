import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {IInputOption, LearnoSelect} from "../../../common/learno-select/learno-select";
import {AbstractControl, FormControl, FormGroup, ReactiveFormsModule, ValidationErrors, Validators} from "@angular/forms";
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Loader} from '../../../common/loader/loader';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import { AuthSession, AuthUser } from '../../../common/auth/auth.models';

@Component({
  selector: 'app-virtual-class-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule,
    LearnoButton
  ],
  templateUrl: './virtual-class-form.html',
  styleUrl: './virtual-class-form.css'
})
export class VirtualClassForm {

  isLoading = signal<boolean>(false);
  days = input<any>([])

  // Time options for 15-minute interval dropdowns
  timeOptions: IInputOption[] = (() => {
    const opts: IInputOption[] = [];
    for (let h = 7; h < 20; h++) {
      for (let m = 0; m < 60; m += 15) {
        const hh = h.toString().padStart(2, '0');
        const mm = m.toString().padStart(2, '0');
        const val = `${hh}:${mm}`;
        opts.push({ value: val, label: val });
      }
    }
    return opts;
  })();

  isEdit = signal<boolean>(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;

  subjects = input<any[]>([]);
  classes = input<any[]>([]);
  teachers = input<any[]>([]);
  select = input<{ [key:string]: string }>({})
  lockTeacher = input<boolean>(false)



  form = new FormGroup({
        classId: new FormControl('', { validators: [Validators.required] }),
        teacherId: new FormControl('', { validators: [Validators.required] }),
        subjectId: new FormControl('', { validators: [Validators.required] }),
        title: new FormControl(''),
        description: new FormControl(''),
        startTime: new FormControl(''),
        endTime: new FormControl(''),
        // isRecurring: new FormControl(false),
    }, { validators: [ (ctrl: AbstractControl) => this.maxOneHourValidator(ctrl) ] })
  constructor(
    private toastService: ToastrService,
    private apiSrv: ApiService,
    private authSrv: AuthService
  ) {
    effect(() => {
      const s = this.select();
      const authUser = this.authSrv.getAuthSession()?.user;
      if (!s || Object.keys(s).length === 0) {
        this.isEdit.set(false);
        if (authUser?.id != null) {
          this.form.patchValue({ teacherId: String(authUser.id) }, { emitEvent: false });
        }
        return;
      }
      const patch: any = {};
      if (s['class_id'] !== undefined && s['class_id'] !== null && s['class_id'] !== '') {
        patch.classId = s['class_id'];
      }
      if (s['subject_id'] !== undefined && s['subject_id'] !== null && s['subject_id'] !== '') {
        patch.subjectId = s['subject_id'];
      }
      if (s['teacher_id'] !== undefined && s['teacher_id'] !== null && s['teacher_id'] !== '') {
        patch.teacherId = s['teacher_id'];
      }
      if (s['day_of_week'] !== undefined && s['day_of_week'] !== null && s['day_of_week'] !== '') {
        patch.dayOfWeek = String(s['day_of_week']);
      }
      if (s['start_time'] !== undefined && s['start_time'] !== null && s['start_time'] !== '') {
        const v = String(s['start_time']);
        const dtLocal = this.toDateTimeLocal(v);
        if (dtLocal) {
          patch.startTime = dtLocal;
        } else if (/^\d{2}:\d{2}$/.test(v)) {
          const now = new Date();
          const pad = (n: number) => String(n).padStart(2, '0');
          patch.startTime = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${v}`;
        }
      }
      if (s['room'] !== undefined && s['room'] !== null && s['room'] !== '') {
        patch.room = s['room'];
      }
      if (s['end_time'] !== undefined && s['end_time'] !== null && s['end_time'] !== '') {
        const v = String(s['end_time']);
        const dtLocal = this.toDateTimeLocal(v);
        if (dtLocal) {
          patch.endTime = dtLocal;
        } else if (/^\d{2}:\d{2}$/.test(v)) {
          const now = new Date();
          const pad = (n: number) => String(n).padStart(2, '0');
          patch.endTime = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${v}`;
        }
      }

      if (Object.keys(patch).length > 0) {
        this.form.patchValue(patch, { emitEvent: false });
      }
      this.isEdit.set(!!s && !!s['id']);
      if (authUser?.id != null && this.lockTeacher()) {
        this.form.patchValue({ teacherId: String(authUser.id) }, { emitEvent: false });
      }
    });

  }

  private toDateTimeLocal(value: string): string | null {
    try {
      const d = new Date(value);
      if (isNaN(d.getTime())) return null;
      const pad = (n: number) => String(n).padStart(2, '0');
      return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    } catch { return null; }
  }

  private toISO(value: string | null | undefined): string | null {
    if (!value) return null;
    try {
      const d = new Date(value);
      if (isNaN(d.getTime())) return null;
      return d.toISOString();
    } catch { return null; }
  }

  private maxOneHourValidator(group: AbstractControl): ValidationErrors | null {
    const startVal = group.get('startTime')?.value as string | null | undefined;
    const endVal = group.get('endTime')?.value as string | null | undefined;
    if (!startVal || !endVal) return null;
    const parse = (v: string): Date | null => {
      if (!v) return null;
      if (/^\d{2}:\d{2}$/.test(v)) {
        const [hh, mm] = v.split(':').map(Number);
        const d = new Date();
        d.setHours(hh, mm, 0, 0);
        return d;
      }
      const d = new Date(v);
      return isNaN(d.getTime()) ? null : d;
    };
    const s = parse(String(startVal));
    const e = parse(String(endVal));
    if (!s || !e) return null;
    const diff = e.getTime() - s.getTime();
    if (diff < 0) return { timeOrder: true };
    if (diff > 60 * 60 * 1000) return { maxDuration: true };
    return null;
  }


  onSubmit() {

    this.isLoading.set(true);

    if (this.form.valid) {
      const payload: any = { ...this.form.value };
      payload.startTime = this.toISO(this.form.value.startTime as string);
      payload.endTime = this.toISO(this.form.value.endTime as string);
      if (!payload.title || String(payload.title).trim() === '') {
        const sOpt = (this.subjects() || []).find(o => String(o.value) === String(payload.subjectId));
        const cOpt = (this.classes() || []).find(o => String(o.value) === String(payload.classId));
        const sLabel = sOpt?.label || this.select()?.['subject_name'] || 'Subject';
        const cLabel = cOpt?.label || this.select()?.['class_name'] || 'Class';
        payload.title = `Virtual Class: ${sLabel} - ${cLabel}`;
      }

      const authUser: AuthUser | null = this.authSrv.getAuthSession()?.user;
      if(!authUser) {
        this.isLoading.set(false);
        this.toastService.error("failed to submit, you are not login")
        return;
      }

      payload.institutionId = authUser.institutionId;
      this.apiSrv.post("/backend/virtual-class/create", payload)
        .subscribe(
          {
            next: (res) => {
              this.form.reset();
              this.toastService.success("virtual class submitted successfully");
              this.submitted.emit({success: true});
            },
            error: (err) => {
              this.isLoading.set(false);
              this.toastService.error("failed to submit");
              console.log(err)
              if (err.status === 401) {
                window.open(err.error.authUrl, '_blank', 'width=600,height=600');
                // this.authSrv.logoutLocal();
                // window.location.href = "/login";
              }
            },
            complete: () => {
              this.isLoading.set(false);
              this.autoCloseBtn.nativeElement.click();
            }
          }
        )

    } else {
      this.isLoading.set(false);
      const groupErr = this.form.errors || {};
      if ((groupErr as any)['timeOrder']) {
        this.toastService.error("End time must be after start time");
      } else if ((groupErr as any)['maxDuration']) {
        this.toastService.error("Class duration cannot exceed one hour");
      } else {
        const err = Object.keys(this.form.controls).reduce((acc: any, key) => {
          const control = this.form.get(key);
          if (control?.errors) {
            acc[key] = control.errors;
          }
          return acc;
        }, {});
        console.log(err);
        this.toastService.error("Please fill in all required fields correctly")
      }
    }
  }

  onEdit() {
    this.isLoading.set(true);
    if (!this.form.valid) {
      this.isLoading.set(false);
      const groupErr = this.form.errors || {};
      if ((groupErr as any)['timeOrder']) {
        this.toastService.error("End time must be after start time");
      } else if ((groupErr as any)['maxDuration']) {
        this.toastService.error("Class duration cannot exceed one hour");
      } else {
        this.toastService.error("Please fill in all required fields correctly")
      }
      return;
    }
    const payload: any = { ...this.form.value, id: this.select()['id'] };
    payload.startTime = this.toISO(this.form.value.startTime as string);
    payload.endTime = this.toISO(this.form.value.endTime as string);
    if (!payload.title || String(payload.title).trim() === '') {
      const sOpt = (this.subjects() || []).find(o => String(o.value) === String(payload.subjectId));
      const cOpt = (this.classes() || []).find(o => String(o.value) === String(payload.classId));
      const sLabel = sOpt?.label || this.select()?.['subject_name'] || 'Subject';
      const cLabel = cOpt?.label || this.select()?.['class_name'] || 'Class';
      payload.title = `Virtual Class: ${sLabel} - ${cLabel}`;
    }
    this.apiSrv.put("/backend/timetable/periods", payload)
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
