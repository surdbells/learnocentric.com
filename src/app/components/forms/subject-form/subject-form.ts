import {Component, input} from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {LearnoSelect} from "../../../common/learno-select/learno-select";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";

@Component({
  selector: 'app-subject-form',
    imports: [
        LearnoInput,
        LearnoSelect,
        ReactiveFormsModule
    ],
  templateUrl: './subject-form.html',
  styleUrl: './subject-form.css'
})
export class SubjectForm {
  action = input<string>('Add');

  formGroup = new FormGroup({
    classroom: new FormControl('', [Validators.required]),
    subject: new FormControl('', [Validators.required])
  })

  onSubmit() {

  }
}
