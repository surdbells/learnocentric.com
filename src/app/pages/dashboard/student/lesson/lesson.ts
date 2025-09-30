import { Component } from '@angular/core';
import {RouterLink, RouterOutlet} from '@angular/router';
import {LessonPageHeader} from '../../../../components/student/lesson/lesson-page-header/lesson-page-header';

@Component({
  selector: 'app-lesson',
  imports: [
    RouterOutlet,
    RouterLink,
    LessonPageHeader
  ],
  templateUrl: './lesson.html',
  styleUrl: './lesson.css'
})
export class Lesson {

}
