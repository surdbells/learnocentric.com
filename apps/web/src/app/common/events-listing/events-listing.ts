import {Component, input} from '@angular/core';

@Component({
  selector: 'app-events-listing',
  imports: [],
  templateUrl: './events-listing.html',
  styleUrl: './events-listing.css'
})
export class EventsListing {
  schedules = input<any[]>([])
}
