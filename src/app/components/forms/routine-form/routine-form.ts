import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {IInputOption, LearnoSelect} from "../../../common/learno-select/learno-select";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Loader} from '../../../common/loader/loader';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-routine-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule,
    Loader,
    LearnoButton
  ],
  templateUrl: './routine-form.html',
  styleUrl: './routine-form.css'
})
export class RoutineForm {

  isLoading = signal<boolean>(false);
  days = input<any>([])

  // Time options for 15-minute interval dropdowns
  timeOptions: IInputOption[] = (() => {
    const opts: IInputOption[] = [];
    for (let h = 0; h < 24; h++) {
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



  form = new FormGroup({
        classId: new FormControl('', { validators: [Validators.required] }),
        teacherId: new FormControl('', { validators: [Validators.required] }),
        subjectId: new FormControl('', { validators: [Validators.required] }),
        dayOfWeek: new FormControl('', { validators: [Validators.required] }),
        startTime: new FormControl('', { validators: [Validators.required] }),
        endTime: new FormControl('', { validators: [Validators.required] }),
        room: new FormControl('', { validators: [Validators.required] })
    })
  constructor(
    private toastService: ToastrService,
    private apiSrv: ApiService,
    private authSrv: AuthService
  ) {
    effect(() => {
      const s = this.select();
      if (!s) { this.form.reset(); return; }
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
        patch.dayOfWeek = s['day_of_week'];
      }
      if (s['start_time'] !== undefined && s['start_time'] !== null && s['start_time'] !== '') {
        patch.startTime = s['start_time'];
      }
      if (s['room'] !== undefined && s['room'] !== null && s['room'] !== '') {
        patch.room = s['room'];
      }
      if (s['end_time'] !== undefined && s['end_time'] !== null && s['end_time'] !== '') {
        patch.endTime = s['end_time'];
      }

      if (Object.keys(patch).length > 0) {
        this.form.patchValue(patch, { emitEvent: false });
      }
      this.isEdit.set(true);
    });
  }


  onSubmit() {

    this.isLoading.set(true);

    if (this.form.valid) {
      this.apiSrv.post("/backend/timetable/periods", this.form.value)
        .subscribe(
          {
            next: (res) => {
              this.form.reset();
              this.toastService.success("submitted successfully");
              this.submitted.emit({success: true});
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
      this.toastService.error("Please fill in all required fields correctly")
    }
  }

  onEdit() {
    this.isLoading.set(true);
    this.apiSrv.put("/backend/timetable/periods", { ...this.form.value, id: this.select()['id'] })
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
