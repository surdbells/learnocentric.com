import {Component, computed, DestroyRef, EventEmitter, inject, Input, OnInit, Output, signal} from '@angular/core';
import {takeUntilDestroyed} from '@angular/core/rxjs-interop';
import {DatePipe} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {Subject, debounceTime, distinctUntilChanged} from 'rxjs';
import {ToastrService} from 'ngx-toastr';
import {ApiService} from '../../common/service/api.service';
import {Icon} from '../../common/icon/icon';

export interface GridColumn {
  key: string;
  label: string;
  sortable?: boolean;
  type?: 'text' | 'badge' | 'date';
  /** For badge columns: maps a cell value to a bootstrap contextual color. */
  badge?: (value: any, row: any) => { text: string; color: string };
}

export interface GridFilter {
  key: string;
  label: string;
  options: { label: string; value: string }[];
}

interface GridMeta { total: number; page: number; per_page: number; total_pages: number; }

/**
 * Production-grade, server-side data grid: pagination, debounced search,
 * filters, sortable columns, multiselect + bulk delete, and per-row actions.
 * Reusable across all list pages.
 */
@Component({
  selector: 'app-data-grid',
  standalone: true,
  imports: [Icon, DatePipe, FormsModule],
  templateUrl: './data-grid.html',
  styleUrl: './data-grid.css',
})
export class DataGrid implements OnInit {
  /** API path returning `{ data, meta }` and supporting `?page&per_page&q&sort&order`. */
  @Input({required: true}) endpoint = '';
  @Input({required: true}) columns: GridColumn[] = [];
  @Input() filters: GridFilter[] = [];
  @Input() actions: Array<'view' | 'edit' | 'delete'> = ['edit', 'delete'];
  @Input() selectable = true;
  @Input() searchable = true;
  @Input() searchPlaceholder = 'Search…';
  @Input() pageSize = 10;
  @Input() idKey = 'id';
  @Input() emptyMessage = 'No records found.';

  @Output() view = new EventEmitter<any>();
  @Output() edit = new EventEmitter<any>();

  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);
  private readonly destroyRef = inject(DestroyRef);

  rows = signal<any[]>([]);
  meta = signal<GridMeta>({total: 0, page: 1, per_page: 10, total_pages: 1});
  loading = signal(false);
  sort = signal<string | null>(null);
  order = signal<'asc' | 'desc'>('asc');
  private searchTerm = signal('');
  filterValues = signal<Record<string, string>>({});
  selected = signal<Set<any>>(new Set());

  private readonly search$ = new Subject<string>();

  readonly allOnPageSelected = computed(() => {
    const rows = this.rows();
    const sel = this.selected();
    return rows.length > 0 && rows.every(r => sel.has(r[this.idKey]));
  });
  readonly selectedCount = computed(() => this.selected().size);

  readonly pageWindow = computed(() => {
    const {page, total_pages} = this.meta();
    const start = Math.max(1, page - 2);
    const end = Math.min(total_pages, start + 4);
    const pages: number[] = [];
    for (let p = Math.max(1, end - 4); p <= end; p++) pages.push(p);
    return pages;
  });

  ngOnInit(): void {
    this.meta.set({...this.meta(), per_page: this.pageSize});
    this.search$
      .pipe(debounceTime(350), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe(term => {
        this.searchTerm.set(term);
        this.goToPage(1);
      });
    this.fetch(1);
  }

  /** Public: parents call this after create/edit to refresh the grid. */
  refresh(): void {
    this.fetch(this.meta().page);
  }

  private fetch(page: number): void {
    this.loading.set(true);
    const params: Record<string, any> = {
      page,
      per_page: this.pageSize,
      q: this.searchTerm(),
      ...this.filterValues(),
    };
    if (this.sort()) { params['sort'] = this.sort(); params['order'] = this.order(); }

    this.api.get<{ data: any[]; meta: GridMeta }>(this.endpoint, {params}).subscribe({
      next: res => {
        this.rows.set(res.data ?? []);
        if (res.meta) this.meta.set(res.meta);
        this.loading.set(false);
      },
      error: () => {
        this.rows.set([]);
        this.loading.set(false);
        this.toast.error('Failed to load records');
      },
    });
  }

  onSearch(value: string): void {
    this.search$.next(value);
  }

  toggleSort(col: GridColumn): void {
    if (!col.sortable) return;
    if (this.sort() === col.key) {
      this.order.set(this.order() === 'asc' ? 'desc' : 'asc');
    } else {
      this.sort.set(col.key);
      this.order.set('asc');
    }
    this.fetch(1);
  }

  setFilter(key: string, value: string): void {
    const next = {...this.filterValues()};
    if (value === '') delete next[key]; else next[key] = value;
    this.filterValues.set(next);
    this.goToPage(1);
  }

  goToPage(page: number): void {
    const tp = this.meta().total_pages;
    const target = Math.min(Math.max(1, page), Math.max(1, tp));
    this.meta.set({...this.meta(), page: target});
    this.fetch(target);
  }

  // --- selection ---
  isSelected(row: any): boolean { return this.selected().has(row[this.idKey]); }

  toggleRow(row: any): void {
    const next = new Set(this.selected());
    const id = row[this.idKey];
    next.has(id) ? next.delete(id) : next.add(id);
    this.selected.set(next);
  }

  toggleAllOnPage(): void {
    const next = new Set(this.selected());
    const rows = this.rows();
    if (this.allOnPageSelected()) {
      rows.forEach(r => next.delete(r[this.idKey]));
    } else {
      rows.forEach(r => next.add(r[this.idKey]));
    }
    this.selected.set(next);
  }

  clearSelection(): void { this.selected.set(new Set()); }

  // --- cell rendering ---
  cell(row: any, col: GridColumn): any { return row?.[col.key]; }

  badgeFor(row: any, col: GridColumn): { text: string; color: string } {
    if (col.badge) return col.badge(row[col.key], row);
    const v = row[col.key];
    const active = v === true || v === 'active' || v === 'published' || v === 'approved';
    return {text: String(v ?? ''), color: active ? 'success' : 'secondary'};
  }

  // --- actions ---
  onDelete(row: any): void {
    if (!confirm('Delete this record? This action cannot be undone.')) return;
    this.api.delete(`${this.endpoint}?id=${row[this.idKey]}`, {confirm: false}).subscribe({
      next: () => { this.toast.success('Deleted'); this.afterMutation(); },
      error: () => this.toast.error('Delete failed'),
    });
  }

  bulkDelete(): void {
    const ids = Array.from(this.selected());
    if (ids.length === 0) return;
    if (!confirm(`Delete ${ids.length} selected record(s)? This cannot be undone.`)) return;
    this.api.post(`${this.endpoint}/bulk-delete`, {ids}).subscribe({
      next: (res: any) => { this.toast.success(`Deleted ${res?.deleted ?? ids.length} record(s)`); this.clearSelection(); this.afterMutation(); },
      error: () => this.toast.error('Bulk delete failed'),
    });
  }

  private afterMutation(): void {
    // If the current page becomes empty after deletion, step back a page.
    const remaining = this.meta().total - 1;
    const maxPage = Math.max(1, Math.ceil(remaining / this.pageSize));
    this.fetch(Math.min(this.meta().page, maxPage));
  }
}
