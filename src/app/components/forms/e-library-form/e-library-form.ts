import {Component, ElementRef, EventEmitter, input, Output, signal, ViewChild, effect} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {LearnoSelect} from "../../../common/learno-select/learno-select";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {Loader} from '../../../common/loader/loader';
import {ApiService} from '../../../common/service/api.service';
import {ToastrService} from 'ngx-toastr';
import {LearnoButton} from '../../../common/learno-button/learno-button';

@Component({
  selector: 'app-e-library-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule,
    LearnoButton
  ],
  templateUrl: './e-library-form.html',
  styleUrl: './e-library-form.css'
})
export class ELibraryForm {

  isLoading = signal<boolean>(false);
  classes = input<any[]>([]);
  subjects = input<any[]>([]);
  action = input<string>('Add')
  select = input<{ [key:string]: string }>({})


  isEdit = signal<boolean>(false);
  @Output() submitted = new EventEmitter<{ success: boolean }>();
  @ViewChild('closebtn', { static: false }) autoCloseBtn!: ElementRef<HTMLButtonElement>;

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
    effect(() => {
      const s = this.select();
      const hasSelection = !!s && Object.keys(s || {}).length > 0;
        this.isEdit.set(hasSelection);

      if (!hasSelection) {
        return;
      }

      // Resolve class and subject IDs from selection, tolerating different shapes
      const classId = (s['class_id'] || s['classId'] || this.findOptionValueByLabel(this.classes(), s['class_name'])) || '';
      const subjectId = (s['subject_id'] || s['subjectId'] || this.findOptionValueByLabel(this.subjects(), s['subject_name'])) || '';

      this.formGroup.patchValue({
        classId: classId,
        subjectId: subjectId,
        title: s['title'] || '',
        description: s['description'] || ''
      });
    });
  }

  private findOptionValueByLabel(options: any[], label?: string): string | undefined {
    if (!label) return undefined;
    const match = (options || []).find((o: any) => (o?.label || '').toString().toLowerCase() === label.toString().toLowerCase());
    return match?.value;
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
            this.formGroup.reset();
            this.toastService.success("submitted successfully")
            this.submitted.emit({success: true});

          },
          error: (err) => {
            this.toastService.error("failed to submit");
            this.isLoading.set(false);
            console.log(err)
          },
          complete: () => {
            this.isLoading.set(false);
            this.autoCloseBtn.nativeElement.click();
          }
        }
      )



      // Send to backend API (example using HttpClient)
      // this.http.post('/api/upload', formData).subscribe(...)
      console.log('FormData ready:', formData.get('file'));
      this.formGroup.reset();
    }

    else {
      this.isLoading.set(false);
      const err = Object.keys(this.formGroup.controls).reduce((acc: any, key) => {
        const control = this.formGroup.get(key);
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
    this.apiSrv.put("/backend/storage/resources", { ...this.formGroup.value, id: this.select()['id'] })
      .subscribe({
        next: (res) => {
          this.formGroup.reset();
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
