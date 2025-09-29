import {Component, input} from '@angular/core';

@Component({
  selector: 'app-learno-modal',
  imports: [],
  templateUrl: './learno-modal.html',
  styleUrl: './learno-modal.css'
})
export class LearnoModal {
    
    modalId = input.required();
    modalAction = input.required();

}
