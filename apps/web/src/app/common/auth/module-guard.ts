import {CanActivateFn, Router, UrlTree} from '@angular/router';
import {inject} from '@angular/core';
import {ToastrService} from 'ngx-toastr';
import {ModuleAccessService} from './module-access.service';

/**
 * Route guard for gateable feature modules. Blocks navigation to a page whose
 * module the school's current plan doesn't grant, sending the user back to their
 * dashboard with an explanation. The backend enforces the same gate (HTTP 402),
 * so this is a UX layer, not the security boundary.
 */
export function moduleGuard(module: string): CanActivateFn {
  return (_route, state): boolean | UrlTree => {
    const access = inject(ModuleAccessService);
    const router = inject(Router);

    if (access.has(module)) {
      return true;
    }

    inject(ToastrService).info(`This feature isn’t included in your school’s current plan.`);
    // Send them to their role's dashboard, the first segment of the *target* URL
    // (router.url isn't the target yet during a hard navigation).
    const seg = state.url.split('/').filter(Boolean)[0] || '';
    return router.parseUrl(seg ? `/${seg}/main` : '/');
  };
}
