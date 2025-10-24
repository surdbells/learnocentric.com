import { Component } from '@angular/core';
import {RouterOutlet} from '@angular/router';

@Component({
  selector: 'app-parent',
  imports: [
    RouterOutlet
  ],
  templateUrl: './parent.html',
  styleUrl: './parent.css'
})
export class Parent {

}
