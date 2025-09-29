import { Component } from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {SchoolProfileForm} from '../../../../../components/admin/school-profile-form/school-profile-form';
import {GradeForm} from '../../../../../components/forms/grade-form/grade-form';

@Component({
  selector: 'app-school-profile',
  imports: [
    PageHeader,
    SchoolProfileForm,
    GradeForm
  ],
  templateUrl: './school-profile.html',
  styleUrl: './school-profile.css'
})
export class SchoolProfile {

}
