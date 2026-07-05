import {CanActivateFn, RedirectCommand, Router} from '@angular/router';
import {inject} from '@angular/core';
import {AuthService} from './auth.service';

export const authGuard: CanActivateFn = (route, state) => {
  const authService = inject(AuthService);
  const router = inject(Router);

  const session = authService.getAuthSession()
  if (Object.values(session).some(val => !val)) {

    const loginPage = router.parseUrl("/");
    return new RedirectCommand(loginPage, {
      skipLocationChange: true,
      browserUrl: loginPage.toString()
    });
  }

  return true;
};
