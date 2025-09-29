import { Component } from '@angular/core';
import {IInputOption, LearnoSelect} from '../../../common/learno-select/learno-select';
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from '@angular/forms';
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {LearnoButton} from "../../../common/learno-button/learno-button";
import { ToastrService } from 'ngx-toastr';

@Component({
  selector: 'app-teacher-form',
  standalone: true,
    imports: [ReactiveFormsModule, LearnoInput, LearnoSelect, LearnoButton],
  templateUrl: './teacher-form.html',
  styleUrl: './teacher-form.css'
})
export class TeacherForm {

  genderOptions: IInputOption[] = [
    { label: 'Male', value: 'male' },
    { label: 'Female', value: 'female' },
    { label: 'Other', value: 'other' }
]

form = new FormGroup({
    email: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.email]}),
    password: new FormControl('', {nonNullable: true, validators: [Validators.required, Validators.minLength(6)]}),
    name: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    gender: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    dob: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    classroom: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
    phone_number: new FormControl('', {nonNullable: true, validators: [Validators.required]}),
})

constructor(private toastService: ToastrService) {
    console.log(this.form.value);
}

onSubmit() {
    console.log(this.form.value)
    if (this.form.valid) {
        console.log('Form submitted:', this.form.value);
        this.toastService.success("submitted successfully")
    } else {
        console.log('Form is invalid:', this.form.errors);
        this.toastService.error("failed to submit")
    }
}

}
