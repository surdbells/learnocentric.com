import {Component, EventEmitter, input, Output} from '@angular/core';
import {LearnoOffset} from '../learno-offset/learno-offset';
import {DatePipe} from '@angular/common';

@Component({
  selector: 'app-data-table',
  imports: [
    LearnoOffset,
    DatePipe
  ],
  templateUrl: './data-table.html',
  styleUrl: './data-table.css'
})
export class DataTable {

    tableHeads = input<string[]>([]);

    tableRows = input<any[]>([]);
    dataFields = input<any[]>([]);

    shouldShowCheckbox = input<boolean>(true);
    shouldShowAction = input<boolean>(true);

    positives: string[] = ['complete', 'completed', 'active', 'success', 'successful'];


  positiveStatus(value: string): boolean {
    const v = String(value || '').toLowerCase();
    const positives = ['complete','completed','active','success','successful'];
    return positives.some(k => v.includes(k));
  }

  @Output() preview = new EventEmitter<{ row: any; anchorSelector: string }>();
}
