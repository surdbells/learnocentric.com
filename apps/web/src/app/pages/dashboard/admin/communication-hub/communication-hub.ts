import {Component, signal} from '@angular/core';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../common/ui';
import {Messages} from '../../messages/messages';
import {Announcements} from '../../announcements/announcements';

/**
 * School-Admin "Communication" hub. Messages and Announcements on one tabbed
 * page; each child renders embedded.
 */
@Component({
  selector: 'app-communication-hub',
  standalone: true,
  imports: [PageHeader, TabBar, Messages, Announcements],
  templateUrl: './communication-hub.html',
})
export class CommunicationHub {
  readonly tabs: TabItem[] = [
    {key: 'messages', label: 'Messages'},
    {key: 'announcements', label: 'Announcements'},
  ];
  tab = signal<string>('messages');
}
