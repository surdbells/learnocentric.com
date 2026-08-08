import {Component, EventEmitter, input, Output} from '@angular/core';
import {DatePipe} from '@angular/common';
import {Icon} from '../../common/icon/icon';

@Component({
  selector: 'app-data-table',
  imports: [Icon,
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
    shouldShowLogoorIcon = input<boolean>(false);

    // Pagination inputs (0 disables pagination for backward compatibility)
    pageSize = input<number>(10);
    currentPage = input<number>(1);

    positives: string[] = ['complete', 'completed', 'active', 'success', 'successful'];


  positiveStatus(value: string): boolean {
    const v = String(value || '').toLowerCase();
    const positives = ['complete','completed','active','success','successful'];
    return positives.some(k => v.includes(k));
  }

  @Output() preview = new EventEmitter<{ row: any; anchorSelector: string }>();

  // Slice rows based on pagination
  visibleRows(): any[] {
    const rows = this.tableRows() || [];
    const size = Number(this.pageSize() || 0);
    if (!size || size <= 0) return rows;
    const page = Math.max(1, Number(this.currentPage() || 1));
    const start = (page - 1) * size;
    const end = start + size;
    return rows.slice(start, end);
  }
}
