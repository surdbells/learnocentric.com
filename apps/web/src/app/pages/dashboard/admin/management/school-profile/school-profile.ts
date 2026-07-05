import {Component} from '@angular/core';
import {ActivatedRoute} from '@angular/router';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {SchoolProfileForm} from '../../../../../components/admin/school-profile-form/school-profile-form';

@Component({
  selector: 'app-school-profile',
  standalone: true,
  imports: [PageHeader, SchoolProfileForm],
  templateUrl: './school-profile.html',
  styleUrl: './school-profile.css',
})
export class SchoolProfile {
  userRole = '';

  constructor(private route: ActivatedRoute) {
    this.userRole = this.route.snapshot.data['user'] ?? '';
  }
}
