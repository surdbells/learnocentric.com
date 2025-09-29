import { Component } from '@angular/core';
import {LearnoButton} from "../../../common/learno-button/learno-button";
import {LearnoInput} from "../../../common/learno-input/learno-input";
import {FormControl, FormGroup, ReactiveFormsModule, Validators} from "@angular/forms";
import {IInputOption, LearnoSelect} from "../../../common/learno-select/learno-select";
import { ToastrService } from 'ngx-toastr';

@Component({
  selector: 'app-student-form',
  standalone: true,
    imports: [
        LearnoButton,
        LearnoInput,
        ReactiveFormsModule,
        LearnoSelect
    ],
  templateUrl: './student-form.html',
  styleUrl: './student-form.css'
})
export class StudentForm {

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
