import {Component, signal} from '@angular/core';
import {IInputOption, LearnoSelect} from '../../../common/learno-select/learno-select';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {LearnoButton} from "../../../common/learno-button/learno-button";
import { ToastrService } from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Loader} from '../../../common/loader/loader';

@Component({
  selector: 'app-teacher-form',
  standalone: true,
  imports: [ReactiveFormsModule, LearnoInput, LearnoSelect, LearnoButton, Loader],
  templateUrl: './teacher-form.html',
  styleUrl: './teacher-form.css'
})
export class TeacherForm {

  genderOptions: IInputOption[] = [
    { label: 'Male', value: 'male' },
    { label: 'Female', value: 'female' },
    { label: 'Other', value: 'other' }
]

form = new FormGroup({
  email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
  password: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.minLength(6)]}),
  firstName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
  lastName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),

    // email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
    // password: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.minLength(6)]}),
    // name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    // gender: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    // dob: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    // classroom: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    // phone_number: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
})
  isLoading = signal<boolean>(false);

  constructor(
    private toastService: ToastrService,
    private apiSrv: ApiService,
    private authSrv: AuthService
  ) {
    // console.log(this.form.value);
  }

  onSubmit() {

    this.isLoading.set(true);

    const {user} = this.authSrv.getAuthSession();
    if(!user) {
      this.isLoading.set(false);
      this.toastService.error("failed to submit, institution is required")
      return;
    }

    if (this.form.valid) {
      this.apiSrv.post("/backend/auth/register", {
        ...this.form.value,
        role: "teacher",
        institutionId: user.institutionId
      }).subscribe(
        {
          next: () => {
            this.form.reset();
            this.toastService.success("submitted successfully")
          },
          error: (err) => {
            this.isLoading.set(false);
            this.toastService.error("failed to submit " + err?.error?.error)
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
