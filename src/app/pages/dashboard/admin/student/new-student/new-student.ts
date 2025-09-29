import { Component } from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {StudentForm} from "../../../../../components/student/student-form/student-form";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {FormControl, FormGroup, Validators} from '@angular/forms';

@Component({
  selector: 'app-new-student',
    imports: [
        PageHeader,
        StudentForm
    ],
  templateUrl: './new-student.html',
  styleUrl: './new-student.css'
})
export class NewStudent {



}
