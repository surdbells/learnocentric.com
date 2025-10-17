import { Component } from '@angular/core';
import {PageHeader} from '../../../../../common/layout/page-header/page-header';
import {DataTable} from '../../../../../components/data-table/data-table';

@Component({
  selector: 'app-student-chat-with-teacher',
  imports: [
    PageHeader,
    DataTable
  ],
  templateUrl: './student-chat-with-teacher.html',
  styleUrl: './student-chat-with-teacher.css'
})
export class StudentChatWithTeacher {

}
