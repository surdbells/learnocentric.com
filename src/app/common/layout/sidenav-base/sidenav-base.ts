import {Component, Inject, input, OnInit, PLATFORM_ID} from '@angular/core';
import {IMenu} from "../../service/user-preference-menu";
import {isPlatformBrowser, NgOptimizedImage} from "@angular/common";
import {Router, RouterLink, RouterLinkActive} from "@angular/router";
import {Preferences} from "../../service/preferences";

@Component({
  selector: 'aside[sidenavBase]',
  standalone: true,
    imports: [
        RouterLink,
        RouterLinkActive,
        NgOptimizedImage
    ],
  templateUrl: './sidenav-base.html',
  styleUrl: './sidenav-base.css'
})
export class SidenavBase implements OnInit{

    menu = input.required<IMenu[]>()
    currentTheme=''
    constructor(
        @Inject(PLATFORM_ID) private platformId: Object,
        private preference: Preferences,
        protected router: Router
        ) {
        this.preference.theme$.subscribe( t => this.currentTheme = t);

        // if (isPlatformBrowser(this.platformId) && this.currentTheme === 'auto') {
        //     this.currentTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        // }
    }

    ngOnInit(): void {
        if (isPlatformBrowser(this.platformId)) {
        }
    }


}
