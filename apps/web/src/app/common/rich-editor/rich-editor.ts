import {Component, forwardRef, inject, input, PLATFORM_ID, signal} from '@angular/core';
import {isPlatformBrowser} from '@angular/common';
import {ControlValueAccessor, FormsModule, NG_VALUE_ACCESSOR} from '@angular/forms';
import {QuillEditorComponent} from 'ngx-quill';
import katex from 'katex';

/**
 * Reusable rich-text editor (Quill + KaTeX) used in place of plain textareas so
 * authors can format text and write precise mathematics (LaTeX via the ƒx
 * button). Stores HTML, works with formControlName and ngModel. SSR-safe, Quill
 * only mounts in the browser.
 */
@Component({
  selector: 'app-rich-editor',
  standalone: true,
  imports: [QuillEditorComponent, FormsModule],
  templateUrl: './rich-editor.html',
  styleUrl: './rich-editor.css',
  providers: [{provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => RichEditor), multi: true}],
})
export class RichEditor implements ControlValueAccessor {
  placeholder = input<string>('Write here…');
  minHeight = input<string>('130px');

  readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));
  value = signal<string>('');
  disabled = signal<boolean>(false);

  private onChange: (v: string) => void = () => {};
  onTouched: () => void = () => {};

  constructor() {
    // Quill's formula module reads window.katex at render time.
    if (this.isBrowser) {
      (window as any).katex = (window as any).katex ?? katex;
    }
  }

  onContentChanged(event: { html: string | null }): void {
    const html = event?.html ?? '';
    this.value.set(html);
    this.onChange(html);
  }

  /**
   * Pasting content copied from Word / Google Docs / the web is slow because
   * Quill keeps every foreign inline style, font and class, then has to render
   * and re-serialise all of it. Strip pasted formatting down to the formats our
   * toolbar actually supports: the paste is fast and the stored HTML stays clean.
   */
  onEditorCreated(quill: any): void {
    const allowed = new Set([
      'bold', 'italic', 'underline', 'script',
      'list', 'indent', 'blockquote', 'code-block', 'code', 'link', 'formula',
    ]);
    quill?.clipboard?.addMatcher(Node.ELEMENT_NODE, (_node: unknown, delta: any) => {
      for (const op of delta?.ops ?? []) {
        if (!op.attributes) continue;
        for (const key of Object.keys(op.attributes)) {
          if (!allowed.has(key)) delete op.attributes[key];
        }
        if (Object.keys(op.attributes).length === 0) delete op.attributes;
      }
      return delta;
    });
  }

  // ControlValueAccessor
  writeValue(v: string | null): void { this.value.set(v ?? ''); }
  registerOnChange(fn: (v: string) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(isDisabled: boolean): void { this.disabled.set(isDisabled); }
}
