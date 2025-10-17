import {Component, input, signal} from '@angular/core';
import {Loader} from '../../../common/loader/loader';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoSelect} from '../../../common/learno-select/learno-select';
import {ApiService} from '../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';

@Component({
  selector: 'app-fees-form',
  imports: [
    Loader,
    ReactiveFormsModule,
    LearnoInput,
    LearnoSelect
  ],
  templateUrl: './fees-form.html',
  styleUrl: './fees-form.css'
})
export class FeesForm {
  isLoading = signal<boolean>(false);
  classes = input<any[]>([]);

  frequencies = [
    {label: 'Monthly', value: 'monthly'},
    {label: 'Quarterly', value: 'quarterly'},
    {label: 'Half Yearly', value: 'half-yearly'},
    {label: 'Yearly', value: 'yearly'}
  ]

  form = new FormGroup({
    name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    amount: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    description: new FormControl('', {nonNullable: false}),
    frequency: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    classId: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
  })

  constructor(
    private readonly apiSrv: ApiService,
    private readonly toastService: ToastrService,
  ) {
  }

  onSubmit() {

    this.isLoading.set(true);

    if (this.form.valid) {
      this.apiSrv.post("/payments/fee-structures", this.form.value)
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
