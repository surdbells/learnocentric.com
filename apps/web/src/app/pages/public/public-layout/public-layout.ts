import {Component} from '@angular/core';
import {RouterLink, RouterLinkActive, RouterOutlet} from '@angular/router';

/** Public marketing shell, shared header, closing CTA band and footer around the routed page. */
@Component({
  selector: 'app-public-layout',
  standalone: true,
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './public-layout.html',
})
export class PublicLayout {
  readonly year = 2026;
}
