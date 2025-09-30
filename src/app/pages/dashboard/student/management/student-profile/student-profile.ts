import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {StudentForm} from '../../../../../components/student/student-form/student-form';

@Component({
  selector: 'app-student-profile',
  imports: [
    PageHeader,
    StudentForm
  ],
  templateUrl: './student-profile.html',
  styleUrl: './student-profile.css'
})
export class StudentProfile {

}
