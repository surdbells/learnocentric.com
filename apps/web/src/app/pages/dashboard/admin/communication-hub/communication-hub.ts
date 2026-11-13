import {Component, computed, effect, inject, signal} from '@angular/core';
import {PageHeader} from '../../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../../common/ui';
import {Messages} from '../../messages/messages';
import {Announcements} from '../../announcements/announcements';
import {ModuleAccessService} from '../../../../common/auth/module-access.service';

/**
 * School-Admin "Communication" hub. Messages and Announcements on one tabbed
 * page; each child renders embedded. Messages disappears when the platform
 * owner has switched direct messaging off.
 */
@Component({
  selector: 'app-communication-hub',
  standalone: true,
  imports: [PageHeader, TabBar, Messages, Announcements],
  templateUrl: './communication-hub.html',
})
export class CommunicationHub {
  private readonly access = inject(ModuleAccessService);

  readonly tabs = computed<TabItem[]>(() => this.access.visible([
    {key: 'messages', label: 'Messages', module: 'messaging'},
    {key: 'announcements', label: 'Announcements'},
  ]));
  tab = signal<string>('messages');

  constructor() {
    effect(() => {
      const keys = this.tabs().map(t => t.key);
      if (keys.length && !keys.includes(this.tab())) this.tab.set(keys[0]);
    });
  }
}
