import {Component, input} from '@angular/core';

@Component({
  selector: 'app-data-table',
  imports: [],
  templateUrl: './data-table.html',
  styleUrl: './data-table.css'
})
export class DataTable {

    tableHeads = input<string[]>([]);

    tableRows = input<any[]>([]);
    dataFields = input<any[]>([]);

    shouldShowCheckbox = input<boolean>(true);
    shouldShowAction = input<boolean>(true);
}
