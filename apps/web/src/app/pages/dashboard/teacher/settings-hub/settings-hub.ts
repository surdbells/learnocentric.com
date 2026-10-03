import {Component, signal} from '@angular/core';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../common/ui';
import {TeacherProfile} from '../management/teacher-profile/teacher-profile';
import {TeacherSettings} from '../settings/settings';

/**
 * Teacher "Settings" hub. Profile and account Settings on one tabbed page;
 * each child renders embedded.
 */
@Component({
  selector: 'app-teacher-settings-hub',
  standalone: true,
  imports: [PageHeader, TabBar, TeacherProfile, TeacherSettings],
  templateUrl: './settings-hub.html',
})
export class TeacherSettingsHub {
  readonly tabs: TabItem[] = [
    {key: 'profile', label: 'Profile'},
    {key: 'settings', label: 'Settings'},
  ];
  tab = signal<string>('profile');
}
