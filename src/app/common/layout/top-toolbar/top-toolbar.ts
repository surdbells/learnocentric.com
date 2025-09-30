import { Component } from '@angular/core';
import {RouterLink} from '@angular/router';

@Component({
  selector: 'nav[topToolbars]',
  standalone: true,
  imports: [
    RouterLink
  ],
  templateUrl: './top-toolbar.html',
  styleUrl: './top-toolbar.css'
})
export class TopToolbar {

}
