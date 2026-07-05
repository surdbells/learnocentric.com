import {Component, EventEmitter, Output, input} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {Icon} from '../../common/icon/icon';

@Component({
  selector: 'app-table-search',
  imports: [Icon, FormsModule],
  standalone: true,
  templateUrl: './table-search.html',
  styleUrl: './table-search.css'
})
export class TableSearch {

  title = input<string>('');
  selectedCount = input<number>(0);
  filterKeys = input<any[]>([]);
  filterValues = input<any[][]>([[]]);

  searchValue: string = '';

  @Output() search = new EventEmitter<string>();
  @Output() filter = new EventEmitter<string>();

  handleSearch() {
    this.search.emit(this.searchValue);
  }

  handleFilter(e: any) {
    this.filter.emit(e?.target?.value);
  }
}
