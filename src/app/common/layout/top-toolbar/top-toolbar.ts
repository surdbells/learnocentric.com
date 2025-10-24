import {Component, signal} from '@angular/core';
import {Router, RouterLink} from '@angular/router';
import {AuthService} from '../../auth/auth.service';
import {Location} from '@angular/common';
import {AuthUser} from '../../auth/auth.models';

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

  user = signal<AuthUser | null>(null)
  constructor(
    private readonly authService: AuthService,
    private readonly router: Router,
    private location: Location,
  ) {
    this.user.set(this.authService.getAuthSession().user);
  }

  signOut() {
    this.authService.logoutLocal()
    this.router.navigate(['/authentication'])
  }

  goBack() {
    this.location.back();
  }
}
