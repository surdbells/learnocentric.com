import {Component, signal} from '@angular/core';
import UserIntro from '../../../../common/user-intro/user-intro';
import {Loader} from '../../../../common/loader/loader';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {AuthService} from '../../../../common/auth/auth.service';
import {AuthUser} from '../../../../common/auth/auth.models';
import {DataTable} from '../../../../components/data-table/data-table';

@Component({
  selector: 'app-parent-main',
  imports: [
    UserIntro,
    Loader,
    PageHeader,
    DataTable
  ],
  templateUrl: './parent-main.html',
  styleUrl: './parent-main.css'
})
export class ParentMain {

  isLoading = signal(false);
  user = signal<AuthUser|null>(null);

  constructor(
    private readonly authSrv: AuthService,
  ) {
    this.user.set(this.authSrv.getAuthSession().user)
  }


}
