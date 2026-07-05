import {Component, input} from '@angular/core';
import {LearnoButton} from '../../common/learno-button/learno-button';
import {Icon} from '../../common/icon/icon';

@Component({
  selector: 'app-learno-modal',
  imports: [Icon, 
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
