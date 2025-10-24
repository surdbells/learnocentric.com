import {Component, EventEmitter, input, Output} from '@angular/core';
import {LearnoOffset} from '../learno-offset/learno-offset';

@Component({
  selector: 'app-data-table',
  imports: [
    LearnoOffset
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

    @Output() preview = new EventEmitter<{ row: any; anchorSelector: string }>();
}
