import {Component, signal} from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {SchoolProfileForm} from '../../../../../components/admin/school-profile-form/school-profile-form';
import {GradeForm} from '../../../../../components/forms/grade-form/grade-form';
import {ActivatedRoute} from '@angular/router';

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

  userRole = ""

  constructor(
    private route: ActivatedRoute
  ) {
    this.userRole = this.route.snapshot.data['user'];
  }

}
