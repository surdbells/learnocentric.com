import {Component, ElementRef, forwardRef, inject, input, OnInit, ViewEncapsulation} from '@angular/core';
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

  private parentForm = inject(FormGroupDirective, { optional: true });
  private elementRef = inject(ElementRef);
  private onChange = (value: any) => {};
  private onTouched = () => {};
  private selectElement: HTMLSelectElement | null =  null;

  
  ngOnInit(): void {
      this.selectElement = this.elementRef.nativeElement.querySelector('select');
      if(this.parentForm && this.formControlName() && this.selectElement) {
          const control = this.parentForm.form.get(this.formControlName());
          if (control) {
            // Subscribe to form control value changes
              control.valueChanges.subscribe(value => {
                if (this.selectElement && this.selectElement.value !== value) {
                  this.selectElement.value = value || '';
              
              }
          });
        }
      }
  }

    writeValue(value: any): void {
     if(this.selectElement) {
      this.selectElement.value = value || '';
     }
    }
    registerOnChange(fn: any): void {
      this.onChange = fn        
    }
    registerOnTouched(fn: any): void {
      this.onTouched = fn;  
    }
    setDisabledState?(isDisabled: boolean): void {
      if(this.selectElement) {
        this.selectElement.disabled = isDisabled;
      }
    }

    onSelectChange(event: Event): void {
      const value = (event.target as HTMLSelectElement).value;
      this.onChange(value);
   }

    onBlur(): void {
        this.onTouched();
    }

}
