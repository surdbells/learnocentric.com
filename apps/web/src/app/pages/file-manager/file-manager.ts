import { Component } from '@angular/core';
import {RouterOutlet} from "@angular/router";
import {SidebarComponent} from "./sidebar/sidebar.component";
import {LearnoButton} from '../../common/learno-button/learno-button';
import {PageHeader} from '../../common/layout/page-header/page-header';

@Component({
  selector: 'app-file-manager',
  imports: [
    RouterOutlet,
    SidebarComponent,
    PageHeader
  ],
  templateUrl: './file-manager.html',
  styleUrl: './file-manager.css'
})
export class FileManager {

  clickedHandler() {

  }
}
