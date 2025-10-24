import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {LearnoSelect} from "../../../common/learno-select/learno-select";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';
import {AuthService} from '../../../common/auth/auth.service';
import {Loader} from '../../../common/loader/loader';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-subject-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule,
    Loader,
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
    code: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
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

      if (!s) { this.form.reset(); return; }
      const patch: any = {};
      if (s['name'] !== undefined && s['name'] !== null && s['name'] !== '') {
        patch.name = s['name'];
      }
      if (s['code'] !== undefined && s['code'] !== null && s['code'] !== '') {
        patch.code = s['code'];
      }
      if (s['description'] !== undefined && s['description'] !== null && s['description'] !== '') {
        patch.description = s['description'];
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
    console.log("edit", this.select())
    console.log("edit", this.form.value)
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
