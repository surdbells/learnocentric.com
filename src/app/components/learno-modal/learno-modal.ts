import {Component, input} from '@angular/core';
import {LearnoButton} from '../../common/learno-button/learno-button';

@Component({
  selector: 'app-learno-modal',
  imports: [
    LearnoButton
  ],
  templateUrl: './learno-modal.html',
  styleUrl: './learno-modal.css'
})
export class LearnoModal {

    modalId = input.required();
    modalAction = input('');
    showBtn = input<boolean>(true)
}
