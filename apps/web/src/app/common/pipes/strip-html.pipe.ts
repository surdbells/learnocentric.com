import {Pipe, PipeTransform} from '@angular/core';

/**
 * Collapses rich-text HTML (e.g. from RichEditor/Quill) to a single line of
 * plain text, for inline previews and truncated meta rows where block markup
 * would break the layout. Legacy plain-text values pass through unchanged.
 */
@Pipe({name: 'stripHtml', standalone: true})
export class StripHtmlPipe implements PipeTransform {
  transform(value: string | null | undefined): string {
    const raw = value ?? '';
    if (!raw) return '';
    // Turn list items and block breaks into separators so words don't run together.
    const spaced = raw
      .replace(/<\/(li|p|div|h[1-6]|blockquote)>/gi, ' ')
      .replace(/<br\s*\/?>/gi, ' ');
    // Drop remaining tags, decode the few entities Quill emits, collapse space.
    const text = spaced
      .replace(/<[^>]+>/g, '')
      .replace(/&nbsp;/gi, ' ')
      .replace(/&amp;/gi, '&')
      .replace(/&lt;/gi, '<')
      .replace(/&gt;/gi, '>')
      .replace(/&quot;/gi, '"')
      .replace(/&#39;/gi, "'")
      .replace(/\s+/g, ' ')
      .trim();
    return text;
  }
}
