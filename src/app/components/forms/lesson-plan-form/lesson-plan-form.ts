import {Component, input} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoSelect} from '../../../common/learno-select/learno-select';

@Component({
  selector: 'app-lesson-plan-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule
  ],
  templateUrl: './lesson-plan-form.html',
  styleUrl: './lesson-plan-form.css'
})
export class LessonPlanForm {

  action = input<string>('Add')

  formGroup = new FormGroup({
    lesson_name: new FormControl('', [Validators.required]),
    classroom: new FormControl('', [Validators.required]),
    subject: new FormControl('', [Validators.required]),
    term: new FormControl('', [Validators.required]),
    resource: new FormControl<File|null>(null, [Validators.required])
  })


  onFileChange(event: Event) {
    console.log('File changed.');
    const input = event.target as any;

    if (input.files && input.files.length > 0) {
      const file = input.files[0];
      this.formGroup.patchValue({ resource: file });
      this.formGroup.get('resource')?.updateValueAndValidity();
    }
  }

  onSubmit() {
    if (this.formGroup.valid) {
      const formData = new FormData();
      Object.keys(this.formGroup.controls).forEach(key => {
        console.log(key, this.formGroup.get(key)?.value);
        formData.append(key, this.formGroup.get(key)?.value);
      });

      // Send to backend API (example using HttpClient)
      // this.http.post('/api/upload', formData).subscribe(...)
      console.log('FormData ready:', formData.get('resource'));
      this.formGroup.setValue({classroom: '', resource: null, lesson_name: '', subject: '', term: ''})
    }
  }


}
