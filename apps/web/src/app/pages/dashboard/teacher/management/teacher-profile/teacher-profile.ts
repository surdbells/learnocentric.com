import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {ProfileForm} from '../../../../../components/forms/profile-form/profile-form';

@Component({
  selector: 'app-teacher-profile',
  imports: [
    PageHeader,
    ProfileForm
  ],
  templateUrl: './teacher-profile.html',
  styleUrl: './teacher-profile.css'
})
export class TeacherProfile {

}
