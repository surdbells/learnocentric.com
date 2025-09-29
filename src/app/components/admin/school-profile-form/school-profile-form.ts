import { Component } from '@angular/core';
import {FormControl, FormGroup, FormsModule, ReactiveFormsModule} from "@angular/forms";
import {LearnoInput} from '../../../common/learno-input/learno-input';
import {GradeForm} from '../../forms/grade-form/grade-form';

@Component({
  selector: 'app-school-profile-form',
  imports: [
    FormsModule,
    ReactiveFormsModule,
    LearnoInput,
    GradeForm
  ],
  templateUrl: './school-profile-form.html',
  styleUrl: './school-profile-form.css'
})
export class SchoolProfileForm {

  form = new FormGroup({
    name: new FormControl(''),
    type: new FormControl(''),
    size: new FormControl(''),
    admin: new FormControl(''),
    address: new FormControl(''),
    email: new FormControl(''),
    logo: new FormControl('')
  })

  onSubmit() {

  }
}
