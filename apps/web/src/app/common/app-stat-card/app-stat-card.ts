import {Component, input} from '@angular/core';
import {RouterLink} from '@angular/router';

@Component({
  selector: 'app-stat-card',
  standalone: true,
  imports: [
    RouterLink
  ],
  templateUrl: './app-stat-card.html',
  styleUrl: './app-stat-card.css'
})
export class AppStatCard {

  label = input.required<string>();
  value = input.required<number>();
  icon =  input.required<string>();
  link = input<string>("");
}
