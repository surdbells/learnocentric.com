import {Component, Inject, OnInit, PLATFORM_ID} from '@angular/core';
import {SidenavToolbar} from '../../common/layout/sidenav-toolbar/sidenav-toolbar';
import {SidenavSm} from '../../common/layout/sidenav-sm/sidenav-sm';
import {SidenavMd} from '../../common/layout/sidenav-md/sidenav-md';
import {SidenavBase} from '../../common/layout/sidenav-base/sidenav-base';
import {RouterOutlet} from '@angular/router';
import {isPlatformBrowser} from "@angular/common";
import {IMenu, UserPreferenceMenu} from "../../common/service/user-preference-menu";
import {TopToolbar} from "../../common/layout/top-toolbar/top-toolbar";
import {AuthUser} from '../../common/auth/auth.models';

@Component({
  selector: 'app-dashboard',
  standalone: true,
    imports: [
        SidenavToolbar,
        SidenavSm,
        SidenavMd,
        SidenavBase,
        RouterOutlet,
        TopToolbar
    ],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.css'
})
export class Dashboard {

    menu: IMenu[] = [];

    constructor(
        @Inject(PLATFORM_ID) private platformId: Object,
        private userPreferenceMenu: UserPreferenceMenu
    ) {
      if (isPlatformBrowser(platformId)) {
        const user: AuthUser = JSON.parse(localStorage.getItem('auth_user') || '{}');
        this.menu = this.userPreferenceMenu[user.role];
      }
    }

  // ngOnInit(): void {
  //   if (isPlatformBrowser(this.platformId)) {
  //     const user: {
  //       name: string,
  //       role: 'school' | 'student' | 'teacher',
  //       email: string
  //     } = JSON.parse(localStorage.getItem('auth_user') || '{}');
  //
  //     this.menu = this.userPreferenceMenu[user.role];
  //     console.log(this.menu)
  //   }
  //       //throw new Error("Method not implemented.");
  //   }
      // Remove this line as it's using an undefined user variable
      // this.menu = this.userPreferenceMenu[user];
}
