import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoSelect} from '../../../common/learno-select/learno-select';
import {Loader} from '../../../common/loader/loader';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-result-form',
  imports: [
    FormsModule,
    LearnoInput,
    LearnoSelect,
    Loader,
    LearnoButton,
    ReactiveFormsModule
  ],
  templateUrl: './result-form.html',
  styleUrl: './result-form.css'
})
export class ResultForm {
  isLoading = signal<boolean>(false);

  subjects = input<any[]>([]);
  classes = input<any[]>([]);
  students = input<any[]>([]);
  select = input<{ [key:string]: string }>({})


  isEdit = signal<boolean>(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;


  form = new FormGroup({
    classId: new FormControl('', { validators: [Validators.required] }),
    studentId: new FormControl('', { validators: [Validators.required] }),
    subjectId: new FormControl('', { validators: [Validators.required] }),
    marksObtained: new FormControl('', { validators: [Validators.required] }),
    totalMarks: new FormControl('', { validators: [Validators.required] }),
    examName: new FormControl('', { validators: [Validators.required] }),
    examDate: new FormControl('', { validators: [Validators.required] }),
    remarks: new FormControl(''),
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
      if (s['student_id'] !== undefined && s['student_id'] !== null && s['student_id'] !== '') {
        patch.studentId = s['student_id'];
      }
      if (s['subject_id'] !== undefined && s['subject_id'] !== null && s['subject_id'] !== '') {
        patch.subjectId = s['subject_id'];
      }
      if (s['marks_obtained'] !== undefined && s['marks_obtained'] !== null && s['marks_obtained'] !== '') {
        patch.marksObtained = s['marks_obtained'];
      }

      if (s['total_marks'] !== undefined && s['total_marks'] !== null && s['total_marks'] !== '') {
        patch.totalMarks = s['total_marks'];
      }

      if (s['exam_name'] !== undefined && s['exam_name'] !== null && s['exam_name'] !== '') {
        patch.examName = s['exam_name'];
      }

      if (s['remarks'] !== undefined && s['remarks'] !== null && s['remarks'] !== '') {
        patch.remarks = s['remarks'];
      }
      const dateVal = (s['exam_date'] ?? s['examDate']);
      if (dateVal !== undefined && dateVal !== null && dateVal !== '') {
        patch.examDate = dateVal;
      }
      if (Object.keys(patch).length > 0) {
        this.form.patchValue(patch, { emitEvent: false });
      }

      // console.log("patch", patch)
      this.isEdit.set(true);
    })
  }

  onEdit() {
    this.isLoading.set(true);
    this.apiSrv.put("/backend/school/grades", { ...this.form.value, id: this.select()['id'] })
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
      this.apiSrv.post("/backend/school/grades", this.form.value)
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
