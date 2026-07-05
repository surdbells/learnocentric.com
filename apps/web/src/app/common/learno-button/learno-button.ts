import {Component, EventEmitter, input, Output} from '@angular/core';
import {Icon} from '../icon/icon';

@Component({
  selector: 'app-learno-button',
  standalone: true,
  imports: [Icon, ],
  templateUrl: './learno-button.html',
  styleUrl: './learno-button.css'
})
export class LearnoButton {
    text = input.required<string>();
    btnColor = input<string>("success")
    icon = input<string|null>(null)
    isLoading = input<boolean>(false)

    @Output() clicked = new EventEmitter<void>();
}
