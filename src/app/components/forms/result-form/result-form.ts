import {
  Component,
  computed,
  effect,
  ElementRef,
  EventEmitter,
  Inject,
  input,
  Output, PLATFORM_ID,
  signal,
  ViewChild
} from '@angular/core';
import {FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators} from '@angular/forms';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoSelect} from '../../../common/learno-select/learno-select';
import {Loader} from '../../../common/loader/loader';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {UtilService} from '../../../common/service/util.service';
import {AuthUser} from '../../../common/auth/auth.models';
import {isPlatformBrowser} from '@angular/common';

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
  @Output() classChange = new EventEmitter<any>();
      // classChangeEvent = this.classChange.asObservable();


  //Todo Emit change event
  onClassChange($event: Event) {
    this.classChange.emit($event);
  }
  isLoading = signal<boolean>(false);

  subjects = input<any[]>([]);
  classes = input<any[]>([]);
  students = input<any[]>([]);
  select = input<{ [key:string]: string }>({})

  // auth/role context
  private user: AuthUser | null = null;
  private role: string | undefined = this.user?.role;

  // role-aware options
  private teacherClasses = signal<any[]>([]);
  filteredClasses = computed(() => {
    if (this.role === 'teacher') {
      const t = this.teacherClasses();
      return t && t.length ? t : [];
    }
    return this.classes();
  });
  filteredStudents = computed(() => {
    const clsId = this.form.get('classId')?.value as any;
    if (clsId) {
      return (this.students() || []).filter((s: any) => String(s.class_id ?? s.classId ?? s.class)?.toString() === String(clsId));
    }
    // Admin: show all students until a class is selected; Teacher: show none until class is selected
    return this.role === 'teacher' ? [] : (this.students() || []);
  });

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
    private authSrv: AuthService,
    private utilSrv: UtilService,
    @Inject(PLATFORM_ID) platformId: object,
  ) {

    // if (isPlatformBrowser(platformId)) {
    //   // Load teacher-specific classes if role is teacher
    //   this.user = this.authSrv.getAuthSession().user;
    //   console.log("user", this.user, "============================")
    //   if (this.role === 'teacher' && this.user?.id) {
    //     this.apiSrv.get<any[]>(`/backend/teacher/classes/${this.user.id}`)
    //       .subscribe({
    //         next: (data) => {
    //           const opts = this.utilSrv.configureForOption(Array.isArray(data) ? data : []);
    //           this.teacherClasses.set(opts);
    //           // Auto-select the only available class for teacher
    //           if (opts.length === 1) {
    //             this.form.get('classId')?.setValue(opts[0].value, { emitEvent: true });
    //           }
    //         },
    //         error: () => {
    //           // fallback: no classes
    //           this.teacherClasses.set([]);
    //         }
    //       });
    //   }
    //
    //   // Reset dependent fields when class changes
    //   this.form.get('classId')?.valueChanges.subscribe(() => {
    //     this.form.get('studentId')?.setValue('', { emitEvent: false });
    //   });
    // }

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
