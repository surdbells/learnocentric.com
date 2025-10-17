import { Component } from '@angular/core';
import {Router, RouterLink} from '@angular/router';
import {AuthService} from '../../auth/auth.service';

@Component({
  selector: 'nav[topToolbars]',
  standalone: true,
  imports: [
    RouterLink
  ],
  templateUrl: './top-toolbar.html',
  styleUrl: './top-toolbar.css'
})
export class TopToolbar {

  constructor(
    private readonly authService: AuthService,
    private readonly router: Router
  ) { }

  signOut() {
    this.authService.logoutLocal()
    this.router.navigate(['/authentication'])
  }
}
