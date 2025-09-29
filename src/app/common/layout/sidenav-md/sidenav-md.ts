import {Component, Inject, input, PLATFORM_ID} from '@angular/core';
import {PreferenceSetting} from '../preference-setting/preference-setting';
import {NgOptimizedImage} from "@angular/common";
import {IMenu} from "../../service/user-preference-menu";
import {Preferences} from "../../service/preferences";
import {Router, RouterLink, RouterLinkActive} from "@angular/router";
import { TranslatePipe } from '../../pipe/t.pipe';

@Component({
  selector: 'aside[sidenavMd]',
  standalone: true,
    imports: [
        PreferenceSetting,
        NgOptimizedImage,
        RouterLink,
        RouterLinkActive,
        TranslatePipe
    ],
  templateUrl: './sidenav-md.html',
  styleUrl: './sidenav-md.css'
})
export class SidenavMd {

    menu = input.required<IMenu[]>()
    constructor(
        @Inject(PLATFORM_ID) private platformId: Object,
        protected router: Router
    ) { }

}
