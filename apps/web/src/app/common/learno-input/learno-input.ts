import {Component, input, forwardRef, signal} from '@angular/core';
import {ReactiveFormsModule, ControlValueAccessor, NG_VALUE_ACCESSOR} from "@angular/forms";

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
export class LearnoInput implements ControlValueAccessor {
    id = input.required<string>();
    label = input.required<string>();
    inputType = input.required<string>();
    formControlName = input.required<string>();
    disabled = input<boolean>(false);

    // The value is bound declaratively in the template ([value]="value()"), so
    // Angular's renderer owns the native input. This is hydration-safe — unlike
    // an imperative querySelector().value write, which can target a detached node
    // after SSR hydration or a view re-render.
    value = signal<string>('');
    cvaDisabled = signal<boolean>(false);

    private onChange = (value: any) => {};
    private onTouched = () => {};

    writeValue(value: any): void {
        this.value.set(value ?? '');
    }

    registerOnChange(fn: any): void {
        this.onChange = fn;
    }

    registerOnTouched(fn: any): void {
        this.onTouched = fn;
    }

    setDisabledState(isDisabled: boolean): void {
        this.cvaDisabled.set(isDisabled);
    }

    onInput(event: Event): void {
        const value = (event.target as HTMLInputElement).value;
        this.value.set(value);
        this.onChange(value);
    }

    onBlur(): void {
        this.onTouched();
    }
}
