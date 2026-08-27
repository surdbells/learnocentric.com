import {Component} from '@angular/core';
import {RouterLink} from '@angular/router';

@Component({
  selector: 'app-public-about',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './about.html',
})
export class PublicAbout {}
