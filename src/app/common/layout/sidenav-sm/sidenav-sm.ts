import {Component, Inject, input, PLATFORM_ID} from '@angular/core';
import {PreferenceSetting} from "../preference-setting/preference-setting";
import {NgOptimizedImage} from "@angular/common";
import {IMenu} from "../../service/user-preference-menu";
import {Preferences} from "../../service/preferences";
import {Router, RouterLink, RouterLinkActive} from "@angular/router";

@Component({
  selector: 'aside[sidenavSm]',
  standalone: true,
    imports: [
        PreferenceSetting,
        NgOptimizedImage,
        RouterLink,
        RouterLinkActive
    ],
  templateUrl: './sidenav-sm.html',
  styleUrl: './sidenav-sm.css'
})
export class SidenavSm {
    menu = input.required<IMenu[]>()
    constructor(
        @Inject(PLATFORM_ID) private platformId: Object,
        protected router: Router
    ) { }
}
