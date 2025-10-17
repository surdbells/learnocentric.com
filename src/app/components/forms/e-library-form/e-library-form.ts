import {Component, input, signal} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {LearnoSelect} from "../../../common/learno-select/learno-select";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {Loader} from '../../../common/loader/loader';
import {ApiService} from '../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';

@Component({
  selector: 'app-e-library-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule,
    Loader
  ],
  templateUrl: './e-library-form.html',
  styleUrl: './e-library-form.css'
})
export class ELibraryForm {

  isLoading = signal<boolean>(false);
  classes = input<any[]>([]);
  subjects = input<any[]>([]);
  action = input<string>('Add')

  formGroup = new FormGroup({
    classId: new FormControl('', [Validators.required]),
    subjectId: new FormControl('', [Validators.required]),
    title: new FormControl('', [Validators.required]),
    file: new FormControl<File|null>(null, [Validators.required]),
    description: new FormControl(''),
  })

  constructor(
    private readonly apiSrv: ApiService,
    private readonly toastService: ToastrService,
  ) {

  }

  onFileChange(event: Event) {
    console.log('File changed.');
    const input = event.target as any;

    if (input.files && input.files.length > 0) {
      const file = input.files[0];
      this.formGroup.patchValue({ file: file });
      this.formGroup.get('file')?.updateValueAndValidity();
    }
  }

  onSubmit() {
    if (this.formGroup.valid) {
      const formData = new FormData();
      Object.keys(this.formGroup.controls).forEach(key => {
        console.log(key, this.formGroup.get(key)?.value);
        formData.append(key, this.formGroup.get(key)?.value);
      });

      this.isLoading.set(true);
      this,this.apiSrv.post('backend/storage/resources', formData)
        .subscribe(
        {
          next: (res) => {
            this.toastService.success("submitted successfully")
          },
          error: (err) => {
            this.toastService.error("failed to submit");
            this.isLoading.set(false);
            console.log(err)
          },
          complete: () => {
            this.isLoading.set(false);
          }
        }
      )



      // Send to backend API (example using HttpClient)
      // this.http.post('/api/upload', formData).subscribe(...)
      console.log('FormData ready:', formData.get('file'));
      this.formGroup.reset();
    }
  }
}
