import {Component, Inject, OnInit, PLATFORM_ID, signal} from '@angular/core';
import {Router, RouterLink} from '@angular/router';
import {AuthService} from '../../auth/auth.service';
import {Location} from '@angular/common';
import {AuthUser} from '../../auth/auth.models';
import { ApiService } from '../../service/api.service';

@Component({
  selector: 'nav[topToolbars]',
  standalone: true,
  imports: [
    RouterLink
  ],
  templateUrl: './top-toolbar.html',
  styleUrl: './top-toolbar.css'
})
export class TopToolbar implements OnInit {

  user = signal<AuthUser | null>(null);
  institution = signal<any | null>(null);
  constructor(
    private readonly authService: AuthService,
    private readonly router: Router,
    private location: Location,
    private readonly apiSrv: ApiService,
    @Inject(PLATFORM_ID) private platformId: Object
  ) {
    this.user.set(this.authService.getAuthSession().user);
  }

  ngOnInit(): void {
    if (this.platformId === 'browser') {
      this.apiSrv.get('/backend/admin/institutions/' + this.user()?.institutionId)
        .subscribe((res) => {
          this.institution.set(res);
        })
      // this.apiSrv.get('/backend/auth/me')
      //   .subscribe((res) => {
      //     this.user.set(res);
      //   })
    }
  }

  signOut() {
    this.authService.logoutLocal()
    this.router.navigate(['/'])
  }

  goBack() {
    this.location.back();
  }
}


// nfp_xnyyAQbir7avtEXijxFuhfMdSnTBuyxrcf28