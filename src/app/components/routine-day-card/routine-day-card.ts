import {Component, input} from '@angular/core';

@Component({
  selector: 'app-routine-day-card',
  imports: [],
  templateUrl: './routine-day-card.html',
  styleUrl: './routine-day-card.css'
})
export class RoutineDayCard {
    day = input.required<string>()
}
