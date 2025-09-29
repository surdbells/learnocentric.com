import {Component, Inject, OnInit, PLATFORM_ID} from '@angular/core';
import {PreferenceSetting} from "../preference-setting/preference-setting";



@Component({
  selector: ' aside[sidenavToolbar] ',
  standalone: true,
    imports: [
        PreferenceSetting
    ],
  templateUrl: './sidenav-toolbar.html',
  styleUrl: './sidenav-toolbar.css'
})


export class SidenavToolbar {



}
