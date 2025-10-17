import {Component, input, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoSelect} from '../../../common/learno-select/learno-select';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Loader} from '../../../common/loader/loader';

@Component({
  selector: 'app-school-class-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule,
    Loader
  ],
  templateUrl: './school-class-form.html',
  styleUrl: './school-class-form.css'
})
export class SchoolClassForm {

  isLoading = signal<boolean>(false);

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
            this.toastService.success("submitted successfully")
          },
          error: (err) => {
            this.isLoading.set(false);
            this.toastService.error("failed to submit")
            console.log(err)
          },
          complete: () => {
            this.isLoading.set(false);
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
