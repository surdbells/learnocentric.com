import {Component, input, signal} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {FormControl, FormGroup, ReactiveFormsModule} from "@angular/forms";
import {LearnoSelect} from '../../../common/learno-select/learno-select';

@Component({
  selector: 'app-grade-form',
  imports: [
    LearnoInput,
    ReactiveFormsModule,
    LearnoSelect
  ],
  templateUrl: './grade-form.html',
  styleUrl: './grade-form.css'
})
export class GradeForm {

  form = new FormGroup({
    name: new FormControl(''),
    type: new FormControl(''),
    size: new FormControl(''),
    admin: new FormControl(''),
    term: new FormControl(''),
    session: new FormControl(''),
    logo: new FormControl('')
  });

  subjects = [1,2,3,4,5,6,7,8,9]
  action = input<string>('');

  onSubmit() {

  }
}
