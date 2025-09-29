import {Component, input} from '@angular/core';

@Component({
  selector: 'app-data-table-numbering',
  imports: [],
  templateUrl: './data-table-numbering.html',
  styleUrl: './data-table-numbering.css'
})
export class DataTableNumbering {

    tableData = input.required<any[]>();
    
    
}
