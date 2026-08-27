import {Component} from '@angular/core';
import {RouterLink} from '@angular/router';

@Component({
  selector: 'app-public-pricing',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './pricing.html',
})
export class PublicPricing {}
