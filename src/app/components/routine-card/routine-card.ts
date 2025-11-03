import {Component, EventEmitter, input, Output} from '@angular/core';
import {RouterLink} from '@angular/router';

export interface IRoutine {
  "id": string;
  "class_id": string;
  "subject_id": string;
  "teacher_id": 2,
  "day_of_week": string;
  "start_time": string;
  "end_time": string;
  "room": string;
  "created_at": string;
  "updated_at": string;
  "subject_name": string;
  "subject_code": string;
  "class_name": string;
  "grade_level": string;
  teacher_first_name: string;
  teacher_last_name: string;
}

@Component({
  selector: 'app-routine-card',
  imports: [
    RouterLink
  ],
  templateUrl: './routine-card.html',
  styleUrl: './routine-card.css'
})
export class RoutineCard {
  routine= input<IRoutine | null>(null)
  tag= input<string>('')

  @Output() preview = new EventEmitter<{ row: any; anchorSelector: string }>();

  get getTime(): string {
    return this.routine()?.start_time.slice(0,5) + ' - ' + this.routine()?.end_time.slice(0,5)
  }
}
