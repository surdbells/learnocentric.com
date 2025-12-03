import {Component, ElementRef, forwardRef, inject, input, OnInit, ViewEncapsulation, signal, computed, HostListener} from '@angular/core';
import {ControlValueAccessor, FormGroupDirective, NG_VALUE_ACCESSOR, ReactiveFormsModule} from '@angular/forms';

export interface IInputOption {
  value: string;
  label: string;
}

@Component({
    selector: '[learnoSelect]',
    imports: [
        ReactiveFormsModule
    ],
    standalone: true,
    templateUrl: './learno-select.html',
    styleUrl: './learno-select.css',
    encapsulation: ViewEncapsulation.None,
    providers: [
        {
        provide: NG_VALUE_ACCESSOR,
        useExisting: forwardRef(() => LearnoSelect),
        multi: true
        }
    ],
})
export class LearnoSelect implements ControlValueAccessor, OnInit {

  id = input.required<string>();
  label = input.required<string>();
  formControlName = input.required<string>();
  options = input.required<IInputOption[]>();
  disabled = input<boolean>(false);
  // Search configuration
  searchable = input<boolean>(true);
  searchPlaceholder = input<string>('Search...');
  placeHolder = input.required<string>();
  multiple = input<boolean>(false);

  // Internal search state
  searchTerm = signal<string>('');
  filteredOptions = computed<IInputOption[]>(() => {
    const term = (this.searchTerm() || '').toLowerCase().trim();
    const opts = this.options() || [];
    if (!this.searchable() || !term) return opts;
    return opts.filter(o => String(o.label || '').toLowerCase().includes(term) || String(o.value || '').toLowerCase().includes(term));
  });

  // Custom dropdown state for searchable mode
  isOpen = signal<boolean>(false);
  selectedValue = signal<string>('');
  selectedValues = signal<string[]>([]);
  selectedLabel = computed<string>(() => {
    if (this.multiple()) {
      const vals = this.selectedValues();
      const opts = this.options() || [];
      const labels = vals.map(v => opts.find(o => String(o.value) === String(v))?.label).filter(l => !!l) as string[];
      return labels.length ? labels.join(', ') : '';
    } else {
      const val = this.selectedValue();
      const found = (this.options() || []).find(o => String(o.value) === String(val));
      return found ? String(found.label) : '';
    }
  });

  private parentForm = inject(FormGroupDirective, { optional: true });
  private elementRef = inject(ElementRef);
  private onChange = (value: any) => {};
  private onTouched = () => {};
  private selectElement: HTMLSelectElement | null =  null;
  // Tracks disabled state applied by Angular forms
  cvaDisabled = false;


  ngOnInit(): void {
      this.selectElement = this.elementRef.nativeElement.querySelector('select');
      if(this.parentForm && this.formControlName() && this.selectElement) {
          const control = this.parentForm.form.get(this.formControlName());
          if (control) {
            // Subscribe to form control value changes
              control.valueChanges.subscribe(value => {
                if (this.multiple()) {
                  const arr = Array.isArray(value) ? value : [];
                  this.selectedValues.set(arr.map(v => String(v || '')));
                } else {
                  if (this.selectElement && this.selectElement.value !== value) {
                    this.selectElement.value = value || '';
                  }
                  this.selectedValue.set(String(value || ''));
                }
              });
        }
      }
  }

    writeValue(value: any): void {
      if (this.multiple()) {
        const arr = Array.isArray(value) ? value : [];
        this.selectedValues.set(arr.map(v => String(v || '')));
      } else {
        if(this.selectElement) {
          this.selectElement.value = value || '';
        }
        this.selectedValue.set(String(value || ''));
      }
    }
    registerOnChange(fn: any): void {
      this.onChange = fn
    }
    registerOnTouched(fn: any): void {
      this.onTouched = fn;
    }
    setDisabledState?(isDisabled: boolean): void {
      this.cvaDisabled = isDisabled;
      if(this.selectElement) {
        this.selectElement.disabled = isDisabled || this.disabled();
      }
    }

    onSelectChange(event: Event): void {
      const el = event.target as HTMLSelectElement;
      if (this.multiple()) {
        const vals = Array.from(el.selectedOptions).map(o => o.value);
        this.selectedValues.set(vals.map(v => String(v || '')));
        this.onChange(vals);
      } else {
        const value = el.value;
        this.onChange(value);
      }
   }

    onBlur(): void {
        this.onTouched();
    }

    onSearchChange(event: Event): void {
      const val = (event.target as HTMLInputElement).value || '';
      this.searchTerm.set(val);
    }

    toggleDropdown(): void {
      if (this.disabled() || this.cvaDisabled) return;
      this.isOpen.set(!this.isOpen());
    }

    selectOption(value: string): void {
      if (this.multiple()) {
        const cur = this.selectedValues();
        const v = String(value || '');
        const next = cur.includes(v) ? cur.filter(x => x !== v) : [...cur, v];
        this.selectedValues.set(next);
        this.onChange(next);
      } else {
        this.selectedValue.set(String(value || ''));
        if (this.selectElement) {
          this.selectElement.value = value || '';
        }
        this.onChange(value);
        this.isOpen.set(false);
      }
    }

    @HostListener('document:click', ['$event'])
    onDocumentClick(event: Event) {
      const target = event.target as Node;
      if (!this.elementRef.nativeElement.contains(target)) {
        this.isOpen.set(false);
      }
    }

}
