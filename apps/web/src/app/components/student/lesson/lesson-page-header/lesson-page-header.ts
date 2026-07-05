import { Component } from '@angular/core';
import {ActivatedRoute, RouterLink} from '@angular/router';

@Component({
  selector: 'app-lesson-page-header',
  imports: [
    RouterLink
  ],
  templateUrl: './lesson-page-header.html',
  styleUrl: './lesson-page-header.css'
})
export class LessonPageHeader {

  subjectId?: string ;
  constructor(
    private router: ActivatedRoute
  ) {
    this.subjectId = this.router.snapshot.paramMap.get('subjectId') ?? undefined;
  }

  studentNavigations = [
    { name: 'Learning', url: ''},
    { name: 'Assignment', url: 'assignment'},
    { name: 'Virtual Class', url: 'virtual-class'},
    { name: 'Resources', url: 'resources'},
    { name: 'Discussion', url: 'discussion'},
    { name: 'Notes', url: 'notes'},
    { name: 'Assessment', url: 'assessment'},
    { name: 'Chat with Teacher', url: 'chat-with-teacher'}
  ]
}
