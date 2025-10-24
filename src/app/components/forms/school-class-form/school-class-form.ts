import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoSelect} from '../../../common/learno-select/learno-select';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Loader} from '../../../common/loader/loader';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-school-class-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule,
    Loader,
    LearnoButton
  ],
  templateUrl: './school-class-form.html',
  styleUrl: './school-class-form.css'
})
export class SchoolClassForm {

  isLoading = signal<boolean>(false);

  select = input<{ [key:string]: string }>({})

  isEdit = signal<boolean>(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;

  form = new FormGroup({
    name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    gradeLevel: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    section: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    academicYear: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
  })
  action = input<string>('');


  constructor(
    private toastService: ToastrService,
    private apiSrv: ApiService,
    private authSrv: AuthService
  ) {
    effect(() => {
      const s = this.select();
      if (!s) { this.form.reset(); return; }
      const patch: any = {};
      if (s['name'] !== undefined && s['name'] !== null && s['name'] !== '') {
        patch.name = s['name'];
      }
      if (s['grade_level'] !== undefined && s['grade_level'] !== null && s['grade_level'] !== '') {
        patch.gradeLevel = s['grade_level'];
      }
      if (s['section'] !== undefined && s['section'] !== null && s['section'] !== '') {
        patch.section = s['section'];
      }
      if (s['academic_year'] !== undefined && s['academic_year'] !== null && s['academic_year'] !== '') {
        patch.academicYear = s['academic_year'];
      }


      // const dateVal = (s['enrollment_date'] ?? s['enrollmentDate']);
      // if (dateVal !== undefined && dateVal !== null && dateVal !== '') {
      //   patch.enrollmentDate = dateVal;
      // }
      if (Object.keys(patch).length > 0) {
        this.form.patchValue(patch, { emitEvent: false });
      }
      // console.log("patch", patch)
      this.isEdit.set(true);
    })
    // console.log(this.form.value);
  }


  onSubmit() {

    this.isLoading.set(true);

    // const {user} = this.authSrv.getAuthSession();
    // if(!user) {
    //   this.isLoading.set(false);
    //   this.toastService.error("failed to submit, institution is required")
    //   return;
    // }

    // console.log(this.form.value, user)
    if (this.form.valid) {
      this.apiSrv.post("/backend/school/classes", this.form.value).subscribe(
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
    console.log("edit", this.select())
    console.log("edit", this.form.value)
    this.isLoading.set(true);
    this.apiSrv.put("/backend/school/classes", { ...this.form.value, id: this.select()['id'] })
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
