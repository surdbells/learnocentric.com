import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-subject-form',
  imports: [
    LearnoInput,
    ReactiveFormsModule,
    LearnoButton
  ],
  templateUrl: './subject-form.html',
  styleUrl: './subject-form.css'
})
export class SubjectForm {
  action = input<string>('Add');
  isLoading = signal<boolean>(false);

  select = input<{ [key:string]: string }>({})

  isEdit = signal<boolean>(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;

  form = new FormGroup({
    name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    code: new FormControl(''),
    description: new FormControl('', {nonNullable: false}),
  })

  constructor(
    private toastService: ToastrService,
    private apiSrv: ApiService,
    private authSrv: AuthService
  ) {
    // console.log(this.form.value);
    effect(() => {
      const s = this.select();

      // Add mode when there is no selected row (or no id).
      if (!s || !s['id']) {
        this.form.reset();
        this.isEdit.set(false);
        return;
      }

      this.form.reset();
      this.form.patchValue({
        name: s['name'] ?? '',
        code: s['code'] ?? '',
        description: s['description'] ?? '',
      }, { emitEvent: false });
      this.isEdit.set(true);
    });
  }


  onSubmit() {

    this.isLoading.set(true);

    if (this.form.valid) {
      this.apiSrv.post("/backend/school/subjects", this.form.value)
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
    this.apiSrv.put("/backend/school/subjects", { ...this.form.value, id: this.select()['id'] })
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
