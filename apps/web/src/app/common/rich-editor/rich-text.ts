import {Component, computed, input} from '@angular/core';

/**
 * Renders rich-text content produced by RichEditor (HTML with KaTeX-rendered
 * maths). Falls back to plain text for legacy plain-string values. Angular's
 * default [innerHTML] sanitiser keeps the formatting and the visible KaTeX
 * spans while stripping anything unsafe.
 */
@Component({
  selector: 'app-rich-text',
  standalone: true,
  imports: [],
  template: `<div class="rich-text ql-editor-view" [innerHTML]="safeHtml()"></div>`,
  styleUrl: './rich-text.css',
})
export class RichText {
  /** HTML (or legacy plain text) to display. */
  value = input<string | null | undefined>('');

  readonly safeHtml = computed<string>(() => {
    const v = this.value() ?? '';
    // Legacy plain text (no tags) → keep line breaks.
    if (v && !/<[a-z][\s\S]*>/i.test(v)) {
      return this.escape(v).replace(/\n/g, '<br>');
    }
    return v;
  });

  private escape(s: string): string {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
}
