import {Component} from '@angular/core';
import {RouterLink} from '@angular/router';

@Component({
  selector: 'app-public-home',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './home.html',
})
export class PublicHome {}
