import {Component, Inject, PLATFORM_ID} from '@angular/core';
import {Preferences} from "../../../common/service/preferences";
import {Router} from "@angular/router";
import {PageHeader} from "../../../common/layout/page-header/page-header";
import {LearnoButton} from "../../../common/learno-button/learno-button";

@Component({
  selector: 'app-chat',
    imports: [
        PageHeader,
        LearnoButton
    ],
  templateUrl: './chat.html',
  styleUrl: './chat.scss'
})
export class Chat {

    currentTheme=''
    constructor(
        // @Inject(PLATFORM_ID) private platformId: Object,
        private preference: Preferences,
        // protected router: Router
    ) {
        this.preference.theme$.subscribe( t => this.currentTheme = t);

        // if (isPlatformBrowser(this.platformId) && this.currentTheme === 'auto') {
        //     this.currentTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        // }
    }

    clickedHandler() {
        
    }
}
