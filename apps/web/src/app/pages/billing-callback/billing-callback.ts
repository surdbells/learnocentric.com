import {afterNextRender, Component, inject} from '@angular/core';
import {ActivatedRoute, Router} from '@angular/router';
import {AuthService} from '../../common/auth/auth.service';
import {roleRoot} from '../../common/auth/portal';

/**
 * Paystack sends the payer back to PAYSTACK_CALLBACK_URL (/billing/callback)
 * with ?reference=…&trxref=…. Forward to the signed-in admin's billing page,
 * which verifies the reference and refreshes the subscription.
 */
@Component({
  selector: 'app-billing-callback',
  standalone: true,
  template: `<div class="d-flex align-items-center justify-content-center py-5 text-secondary">
    <span class="spinner-border spinner-border-sm me-2"></span> Confirming your payment…
  </div>`,
})
export class BillingCallback {
  constructor() {
    const route = inject(ActivatedRoute);
    const router = inject(Router);
    const auth = inject(AuthService);
    // Browser only: the session lives in localStorage, which SSR can't see.
    afterNextRender(() => {
      const q = route.snapshot.queryParamMap;
      const reference = q.get('reference') || q.get('trxref') || '';
      const user = auth.getAuthSession()?.user;
      const target = user ? roleRoot(user.role) + '/management/billing' : '/login';
      router.navigate([target], {queryParams: reference ? {reference} : {}, replaceUrl: true});
    });
  }
}
