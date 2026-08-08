import {Component, EventEmitter, input, Output, signal, effect, ViewChild, ElementRef} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {LearnoSelect} from '../../../common/learno-select/learno-select';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-enrollment-form',
  imports: [
    LearnoSelect,
    LearnoInput,
    ReactiveFormsModule,
    LearnoButton
  ],
  templateUrl: './enrollment-form.html',
  styleUrl: './enrollment-form.css'
})
export class EnrollmentForm {
  isLoading = signal<boolean>(false);

  classes = input<any[]>([]);
  students = input<any[]>([]);
  select = input<{ [key:string]: string }>({})

  isEdit = signal<boolean>(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;



  form = new FormGroup({
    classId: new FormControl('', { validators: [Validators.required] }),
    studentId: new FormControl('', { validators: [Validators.required] }),
    enrollmentDate: new FormControl('', { validators: [Validators.required] }),
  })
  constructor(
    private toastService: ToastrService,
    private apiSrv: ApiService,
    private authSrv: AuthService
  ) {
    // React to incoming preselected values and patch the form when provided
    effect(() => {
      const s = this.select();
      if (!s) { this.form.reset(); this.isEdit.set(false); return; }
      const patch: any = {};
      if (s['class_id'] !== undefined && s['class_id'] !== null && s['class_id'] !== '') {
        patch.classId = s['class_id'];
      }
      if (s['student_id'] !== undefined && s['student_id'] !== null && s['student_id'] !== '') {
        patch.studentId = s['student_id'];
      }
      const dateVal = (s['enrollment_date'] ?? s['enrollmentDate']);
      if (dateVal !== undefined && dateVal !== null && dateVal !== '') {
        patch.enrollmentDate = dateVal;
      }
      if (Object.keys(patch).length > 0) {
        this.form.patchValue(patch, { emitEvent: false });
      }

      // console.log("patch", patch)
      this.isEdit.set(true);
    });
  }

  onEdit() {
    this.isLoading.set(true);
    this.apiSrv.put("/backend/school/enrollments", { ...this.form.value, id: this.select()['id'] })
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

  onSubmit() {

    this.isLoading.set(true);

    if (this.form.valid) {
      this.apiSrv.post("/backend/school/enrollments", this.form.value)
        .subscribe(
          {
            next: (res) => {
              this.form.reset();
              this.toastService.success("submitted successfully")
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

}
