import {Component, signal} from '@angular/core';
import {LearnoButton} from "../../../common/learno-button/learno-button";
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {IInputOption, LearnoSelect} from "../../../common/learno-select/learno-select";
import { ToastrService } from 'ngx-toastr';
import {Loader} from '../../../common/loader/loader';
import {ApiService} from '../../../common/service/api.service';
import {AuthSession} from '../../../common/auth/auth.models';
import {AuthService} from '../../../common/auth/auth.service';

@Component({
  selector: 'app-student-form',
  standalone: true,
  imports: [
    LearnoButton,
    LearnoInput,
    ReactiveFormsModule,
    LearnoSelect,
    Loader
  ],
  templateUrl: './student-form.html',
  styleUrl: './student-form.css'
})
export class StudentForm {

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
        classroom: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
        // institutionId: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    })
  isLoading = signal<boolean>(false);

    constructor(
      private toastService: ToastrService,
      private apiSrv: ApiService,
      private authSrv: AuthService
      ) {
        console.log(this.form.value);
    }

    onSubmit() {

      this.isLoading.set(true);

      const {user} = this.authSrv.getAuthSession();
      if(!user) {
        this.isLoading.set(false);
        this.toastService.error("failed to submit, institution is required")
        return;
      }

        // console.log(this.form.value, user)
        if (this.form.valid) {
          this.apiSrv.post("/backend/auth/register", {
            ...this.form.value,
            role: "student",
            institutionId: user.institutionId
          }).subscribe(
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
