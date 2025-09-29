import {Component, input} from '@angular/core';

@Component({
  selector: 'app-data-table',
  imports: [],
  templateUrl: './data-table.html',
  styleUrl: './data-table.css'
})
export class DataTable {

    tableHeads = input.required<string[]>();

    tableRows = input.required<any[]>();
}
