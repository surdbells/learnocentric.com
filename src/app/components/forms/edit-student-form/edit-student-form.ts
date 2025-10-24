import {Component, effect, ElementRef, EventEmitter, input, Output, signal, ViewChild} from '@angular/core';
import {FormControl, FormGroup, FormsModule, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoSelect} from '../../../common/learno-select/learno-select';
import {Loader} from '../../../common/loader/loader';
import {LearnoButton} from '../../../common/learno-button/learno-button';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../../common/service/api.service';

@Component({
  selector: 'app-edit-student-form',
  imports: [
    FormsModule,
    LearnoInput,
    LearnoSelect,
    Loader,
    ReactiveFormsModule,
    LearnoButton
  ],
  templateUrl: './edit-student-form.html',
  styleUrl: './edit-student-form.css'
})
export class EditStudentForm {

  isLoading = signal(false);
  select = input<{ [key:string]: string }>({})

  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;


  constructor(
    private toastService: ToastrService,
    private readonly apiSrv: ApiService,
  ) {
    effect(() => {
      const s = this.select();
      if (!s) { return; }
      const patch: any = {};
      if (s['email'] !== undefined && s['email'] !== null && s['email'] !== '') {
        patch.email = s['email'];
      }
      if (s['first_name'] !== undefined && s['first_name'] !== null && s['first_name'] !== '') {
        patch.firstName = s['first_name'];
      }
      if (s['last_name'] !== undefined && s['last_name'] !== null && s['last_name'] !== '') {
        patch.lastName = s['last_name'];
      }

      if(Object.keys(patch).length > 0) {
        this.form.patchValue(patch, { emitEvent: false });
      }
    });
  }

  form = new FormGroup({
    email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
    // password: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.minLength(6)]}),
    firstName: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    lastName: new FormControl('', {nonNullable: true, validators: [Validators.required]})
    // institutionId: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
  })


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
