import {Component, computed, inject, input} from '@angular/core';
import {Router} from '@angular/router';
import {Icon} from '../../icon/icon';

@Component({
  selector: 'app-page-header',
  standalone: true,
  imports: [Icon, ],
  templateUrl: './page-header.html',
  styleUrl: './page-header.css',
})
export class PageHeader {
  private readonly router = inject(Router);

  icon = input<string>('');
  action = input<string>('');

  /** Readable breadcrumb trail derived from the URL (no dead links). */
  readonly crumbs = computed<string[]>(() => {
    return this.router.url
      .split('?')[0]
      .split('/')
      .filter(Boolean)
      .map((seg) => seg
        .replace(/-/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase()));
  });
}
