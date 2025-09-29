import { Component } from '@angular/core';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import { CalendarOptions } from '@fullcalendar/core';
import { FullCalendarModule } from '@fullcalendar/angular';
import dayGridPlugin from '@fullcalendar/daygrid';

@Component({
  selector: 'app-school-event',
  standalone: true,
  imports: [PageHeader, FullCalendarModule ],
  templateUrl: './school-event.html',
  styleUrl: './school-event.css'
})
export class SchoolEvent {
  calendarOptions: CalendarOptions = {
    initialView: 'dayGridMonth',
    plugins: [dayGridPlugin]
  };
}
