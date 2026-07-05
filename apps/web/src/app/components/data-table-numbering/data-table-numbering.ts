import {Component, computed, effect, EventEmitter, input, OnInit, Output} from '@angular/core';

@Component({
  selector: 'app-data-table-numbering',
  imports: [],
  templateUrl: './data-table-numbering.html',
  styleUrl: './data-table-numbering.css'
})

export class DataTableNumbering implements OnInit {
  tableData = input<any[]>([]);
  pageSize = input<number>(10);
  currentPage = input<number>(1);
  count = input<number>(10);


  @Output() pageChange = new EventEmitter<number>();

  pages = computed(() => {
    const total = this.tableData()?.length ?? 0;
    const size = Math.max(1, Number(this.pageSize?.() ?? 10));
    const count = Math.ceil(total / size) || 1;
    return Array.from({ length: count }, (_, i) => i + 1);
  });
Math: any;

  // constructor() {
  //   // Initialize page array with effect to react to tableData changes
  //   effect(() => {
  //     console.log(this.tableData());
  //     this.page = new Array(Math.ceil(this.tableData()?.length/this.count())).fill('o');
  //   });
  // }

  ngOnInit(): void {
  }
}

