import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {ApiService} from '../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {LearnoSelect} from '../../../common/learno-select/learno-select';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-payment-form',
  imports: [
    ReactiveFormsModule,
    LearnoSelect,
    LearnoInput,
    LearnoButton
  ],
  templateUrl: './payment-form.html',
  styleUrl: './payment-form.css'
})
export class PaymentForm {
  isLoading = signal<boolean>(false);
  fees = input<any[]>([]);
  userId = input<string>('');
  select = input<{ [key:string]: string }>({})

  isEdit = signal<boolean>(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;

  paymentMethods = [
    {label: 'Cash', value: 'cash'},
    {label: 'Cheque', value: 'cheque'},
    {label: 'Bank Transfer', value: 'bank-transfer'},
    {label: 'Paystack', value: 'paystack'},
    {label: 'Paypal', value: 'paypal'},
    {label: 'Stripe', value: 'stripe'}
  ]


  form = new FormGroup({
    paymentMethod: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    amount: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    feeStructureId: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
  })

  constructor(
    private readonly apiSrv: ApiService,
    private readonly toastService: ToastrService,
  ) {

    effect(() => {
      const s = this.select();
      console.log(s, "")
      if (!s) { this.form.reset(); return; }
      const patch: any = {};
      if (s['class_id'] !== undefined && s['class_id'] !== null && s['class_id'] !== '') {
        patch.classId = s['class_id'];
      }
      if (s['amount'] !== undefined && s['amount'] !== null && s['amount'] !== '') {
        patch.amount = s['amount'];
      }
      if (s['description'] !== undefined && s['description'] !== null && s['description'] !== '') {
        patch.description = s['description'];
      }
      if (s['frequency'] !== undefined && s['frequency'] !== null && s['frequency'] !== '') {
        patch.frequency = s['frequency'];
      }
      if (s['name'] !== undefined && s['name'] !== null && s['name'] !== '') {
        patch.name = s['name'];
      }

      if (Object.keys(patch).length > 0) {
        this.form.patchValue(patch, { emitEvent: false });
      }
      this.isEdit.set(true);
    });
  }

  onSubmit() {
    this.isLoading.set(true);
    if (this.form.valid) {
      this.apiSrv.post("/backend/payments/create",{ ...this.form.value, userId: this.userId() })
        .subscribe(
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
    this.isLoading.set(true);
    this.apiSrv.put("/backend/payments/fee-structures", { ...this.form.value, id: this.select()['id'] })
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
