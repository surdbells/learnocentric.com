import {Component, input, signal} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {LearnoSelect} from "../../../common/learno-select/learno-select";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Loader} from '../../../common/loader/loader';

@Component({
  selector: 'app-routine-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule,
    Loader
  ],
  templateUrl: './routine-form.html',
  styleUrl: './routine-form.css'
})
export class RoutineForm {

  isLoading = signal<boolean>(false);

  days = input<any[]>([]);
  subjects = input<any[]>([]);
  classes = input<any[]>([]);
  teachers = input<any[]>([]);


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
    // console.log(this.form.value);
  }

  onSubmit() {

    this.isLoading.set(true);

    if (this.form.valid) {
      this.apiSrv.post("/backend/timetable/periods", this.form.value)
        .subscribe(
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
