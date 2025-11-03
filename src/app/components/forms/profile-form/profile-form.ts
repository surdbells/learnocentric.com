import {Component, OnInit, signal} from '@angular/core';
import {LearnoButton} from "../../../common/learno-button/learno-button";
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {IInputOption} from '../../../common/learno-select/learno-select';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {SkeletonLoader} from '../../../common/skeleton-loader/skeleton-loader';

@Component({
  selector: 'app-profile-form',
  imports: [
    LearnoButton,
    LearnoInput,
    ReactiveFormsModule,
    SkeletonLoader
  ],
  templateUrl: './profile-form.html',
  styleUrl: './profile-form.css'
})
export class ProfileForm implements OnInit{


  form = new FormGroup({
    _email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
    firstName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    address: new FormControl(''),
    phone: new FormControl(''),
    lastName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    dateOfBirth: new FormControl(''),
    // profileImageUrl: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
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

  ngOnInit(): void {
    this.isLoading.set(true);
    this.apiSrv.get("/backend/auth/me")
      .subscribe({
        next: (res) => {
          this.form.patchValue({ ...res, _email: res?.email }, {emitEvent: false});
          this.isLoading.set(false);
        },
        error: (err) => {
          this.isLoading.set(false);
          this.toastService.error("failed to fetch profile")
          console.log(err)
        },
        complete: () => {}
      })
    }

  onSubmit() {

    this.isLoading.set(true);

    const {user} = this.authSrv.getAuthSession();
    if(!user) {
      this.isLoading.set(false);
      this.toastService.error("failed to submit, login first")
      return;
    }

    // console.log(this.form.value, user)
    if (this.form.valid) {
      this.apiSrv.put("/backend/auth/profile", {
        ...this.form.value,
      }).subscribe(
        {
          next: (res) => {
            this.toastService.success("updated successfully")
          },
          error: (err) => {
            this.isLoading.set(false);
            this.toastService.error("failed to update")
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
      this.toastService.error("Unable to update your profile")
    }
  }
}
