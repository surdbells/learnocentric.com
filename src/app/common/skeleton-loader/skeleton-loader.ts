import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

// A reusable skeleton loader with variants for text, card, table, and avatar.
// Usage:
// <app-skeleton-loader [isLoading]="loading" variant="table" [rows]="5" [columns]="4">
//   <!-- Actual content shown when not loading -->
//   <app-data-table ...></app-data-table>
// </app-skeleton-loader>

@Component({
  selector: 'app-skeleton-loader',
  standalone: true,
  imports: [
    CommonModule
  ],
  templateUrl: './skeleton-loader.html',
  styleUrls: ['./skeleton-loader.css']
})
export class SkeletonLoader {
  // Whether to show skeleton or projected content
  @Input() isLoading: boolean = false;

  // Variant type
  @Input() variant: 'text' | 'card' | 'table' | 'avatar' = 'text';

  // For text lines or card content blocks
  @Input() lines: number = 3;

  // For tables
  @Input() rows: number = 5;
  @Input() columns: number = 3;

  // Show shimmer animation
  @Input() animated: boolean = true;

  // Optional sizes for avatar or blocks
  @Input() width?: string;  // e.g., '100%', '200px'
  @Input() height?: string; // e.g., '16px', '120px'

  protected asArray(count: number): number[] {
    return Array.from({ length: Math.max(0, Math.floor(count || 0)) }, (_, i) => i);
  }
}
