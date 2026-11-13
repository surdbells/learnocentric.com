import {Component, computed, inject, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {Icon} from '../../../../common/icon/icon';
import {ProfileForm} from '../../../../components/forms/profile-form/profile-form';
import {ApiService} from '../../../../common/service/api.service';
import {AuthService} from '../../../../common/auth/auth.service';

/**
 * Parent "My Profile": the parent's own details (editable), the children
 * linked to them by the school, and account security. Backed by the session
 * user and /analytics/children.
 */
@Component({
  selector: 'app-parent-profile',
  standalone: true,
  imports: [PageHeader, Icon, RouterLink, ProfileForm],
  templateUrl: './parent-profile.html',
})
export class ParentProfile {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);

  editing = signal(false);
  children = signal<any[]>([]);
  loading = signal(true);

  readonly user = signal<any>(this.auth.getAuthSession()?.user ?? null);
  readonly fullName = computed(() => `${this.user()?.firstName ?? ''} ${this.user()?.lastName ?? ''}`.trim() || 'Parent');
  readonly twoFactorOn = computed(() => this.user()?.two_factor_enabled === true);

  constructor() {
    this.api.get<any>('/backend/analytics/children').subscribe({
      next: (r) => { this.children.set(r?.data ?? []); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  onEdited(): void {
    this.editing.set(false);
    this.user.set(this.auth.getAuthSession()?.user ?? null);
  }
}
