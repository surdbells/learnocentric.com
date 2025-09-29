import { Component } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
// import { CustomizerSettingsService } from '../../../customizer-settings/customizer-settings.service';

@Component({
    selector: 'app-fm-sidebar',
    imports: [RouterLink, RouterLinkActive],
    templateUrl: './sidebar.component.html',
    styleUrl: './sidebar.component.scss'
})
export class SidebarComponent {

    constructor(
        // public themeService: {} //CustomizerSettingsService
    ) {}

}