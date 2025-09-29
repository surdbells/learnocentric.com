import {Component, input, signal} from '@angular/core';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {LearnoSelect} from '../../../common/learno-select/learno-select';

@Component({
  selector: 'app-school-class-form',
  imports: [
    LearnoInput,
    LearnoSelect,
    ReactiveFormsModule
  ],
  templateUrl: './school-class-form.html',
  styleUrl: './school-class-form.css'
})
export class SchoolClassForm {

  formGroup = new FormGroup({
    classroom: new FormControl('', [Validators.required])
  })
  action = input<string>('');

  onSubmit() {

  }
}
