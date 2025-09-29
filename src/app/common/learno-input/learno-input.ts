import {Component, input, forwardRef, OnInit, inject, ElementRef} from '@angular/core';
import {ReactiveFormsModule, ControlValueAccessor, NG_VALUE_ACCESSOR, FormGroupDirective} from "@angular/forms";

@Component({
  selector: '[learnoInput]',
  standalone: true,
  imports: [
    ReactiveFormsModule
  ],
  templateUrl: './learno-input.html',
  styleUrl: './learno-input.css',
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => LearnoInput),
      multi: true
    }
  ]
})
export class LearnoInput implements ControlValueAccessor, OnInit {
    id = input.required<string>();
    label = input.required<string>();
    inputType = input.required<string>();
    formControlName = input.required<string>();

    private parentForm = inject(FormGroupDirective, { optional: true });
    private elementRef = inject(ElementRef);
    private onChange = (value: any) => {};
    private onTouched = () => {};
    private inputElement: HTMLInputElement | null = null;

    ngOnInit() {
        this.inputElement = this.elementRef.nativeElement.querySelector('input');
        if (this.parentForm && this.formControlName() && this.inputElement) {
            const control = this.parentForm.form.get(this.formControlName());
            if (control) {
                // Subscribe to form control value changes
                control.valueChanges.subscribe(value => {
                    if (this.inputElement && this.inputElement.value !== value) {
                        this.inputElement.value = value || '';
                    }
                });
            }
        }
    }

    writeValue(value: any): void {
        if (this.inputElement) {
            this.inputElement.value = value || '';
        }
    }

    registerOnChange(fn: any): void {
        this.onChange = fn;
    }

    registerOnTouched(fn: any): void {
        this.onTouched = fn;
    }

    setDisabledState(isDisabled: boolean): void {
        if (this.inputElement) {
            this.inputElement.disabled = isDisabled;
        }
    }

    onInput(event: Event): void {
        const value = (event.target as HTMLInputElement).value;
        this.onChange(value);
    }

    onBlur(): void {
        this.onTouched();
    }
}
