import {Component, input} from '@angular/core';

@Component({
  selector: 'app-stat-card',
  standalone: true,
  imports: [],
  templateUrl: './app-stat-card.html',
  styleUrl: './app-stat-card.css'
})
export class AppStatCard {

  label = input.required<string>();
  value = input.required<string>();
  icon =  input.required<string>();
}
