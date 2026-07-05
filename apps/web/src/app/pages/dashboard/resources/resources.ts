import {Component, inject, signal} from '@angular/core';
import {ToastrService} from 'ngx-toastr';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {ApiService} from '../../../common/service/api.service';

/**
 * Learning resources available to the institution through its assigned content
 * package (spec §8). Read-only view for staff and learners.
 */
@Component({
  selector: 'app-resources',
  standalone: true,
  imports: [PageHeader],
  templateUrl: './resources.html',
  styleUrl: './resources.css',
})
export class Resources {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastrService);

  loading = signal(true);
  package = signal<any | null>(null);
  resources = signal<any[]>([]);

  constructor() {
    this.api.get<any>('/backend/content/my-resources').subscribe({
      next: (res) => {
        this.package.set(res?.package ?? null);
        this.resources.set(res?.resources ?? []);
        this.loading.set(false);
      },
      error: () => { this.loading.set(false); this.toast.error('Could not load resources'); },
    });
  }

  typeIcon(type: string): string {
    return ({video: 'smart_display', document: 'description', assignment: 'assignment', quiz: 'quiz', interactive: 'touch_app'} as Record<string, string>)[type] ?? 'folder';
  }
  typeColor(type: string): string {
    return ({video: 'danger', document: 'primary', assignment: 'warning', quiz: 'info', interactive: 'success'} as Record<string, string>)[type] ?? 'secondary';
  }
}
