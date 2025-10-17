import {Component, effect, input, OnInit} from '@angular/core';

@Component({
  selector: 'app-data-table-numbering',
  imports: [],
  templateUrl: './data-table-numbering.html',
  styleUrl: './data-table-numbering.css'
})

export class DataTableNumbering implements OnInit {
  tableData = input<any[]>([]);
  count = input<number>(10);
  size = Math.ceil((this?.tableData()?.length ?? 0) / (this.count?.() ?? 1));
  arr = Array.from({length: this.size}, () => Math.random());
  page: any[] = [];

  constructor() {
    // Initialize page array with effect to react to tableData changes
    effect(() => {
      console.log(this.tableData());
      this.page = new Array(Math.ceil(this.tableData().length/this.count())).fill('o');
    });
  }

  ngOnInit(): void {
  }
}

