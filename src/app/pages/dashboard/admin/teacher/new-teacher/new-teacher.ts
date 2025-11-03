import {Component, Inject, Injectable, PLATFORM_ID, signal} from '@angular/core';
import {PageHeader} from "../../../../../common/layout/page-header/page-header";
import {TeacherForm} from "../../../../../components/teacher/teacher-form/teacher-form";
import {LearnoButton} from "../../../../../common/learno-button/learno-button";
import {AuthUser} from '../../../../../common/auth/auth.models';
import {AuthService} from '../../../../../common/auth/auth.service';
import {isPlatformBrowser} from '@angular/common';

@Component({
  selector: 'app-new-teacher',
    imports: [
        PageHeader,
        TeacherForm,
    ],
  templateUrl: './new-teacher.html',
  styleUrl: './new-teacher.css'
})
export class NewTeacher {

  user = signal<AuthUser | null>(null);
  constructor(
    private authSrv: AuthService,
    @Inject(PLATFORM_ID) platformId: object
  ) {
      if(isPlatformBrowser(platformId)) {
        this.user.set(this.authSrv.getAuthSession().user)
      }
  }
}
