import { Component } from '@angular/core';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {LearnoSelect} from "../../../common/learno-select/learno-select";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";

@Component({
  selector: 'app-routine-form',
    imports: [
        LearnoInput,
        LearnoSelect,
        ReactiveFormsModule
    ],
  templateUrl: './routine-form.html',
  styleUrl: './routine-form.css'
})
export class RoutineForm {

    formGroup = new FormGroup({
        classroom: new FormControl('', { validators: [Validators.required] }),
        teacher: new FormControl('', { validators: [Validators.required] }),
        subject: new FormControl('', { validators: [Validators.required] }),
        day: new FormControl('', { validators: [Validators.required] }),
        startHour: new FormControl('', { validators: [Validators.required] }),
        startMinute: new FormControl('', { validators: [Validators.required] }),
        endingHour: new FormControl('', { validators: [Validators.required] }),
        endingMinute: new FormControl('', { validators: [Validators.required] }),
    })
}
