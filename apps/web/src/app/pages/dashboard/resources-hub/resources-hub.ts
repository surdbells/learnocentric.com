import {Component, signal} from '@angular/core';
import {PageHeader} from '../../../common/layout/page-header/page-header';
import {TabBar, TabItem} from '../../../common/ui';
import {Resources} from '../resources/resources';
import {ResourceViewer} from '../resources/resource-viewer/resource-viewer';

/**
 * Shared "Resources" hub (admin + teacher). Learning Resources and the
 * Resource Viewer on one tabbed page; each child renders embedded.
 */
@Component({
  selector: 'app-resources-hub',
  standalone: true,
  imports: [PageHeader, TabBar, Resources, ResourceViewer],
  templateUrl: './resources-hub.html',
})
export class ResourcesHub {
  readonly tabs: TabItem[] = [
    {key: 'library', label: 'Resources'},
    {key: 'viewer', label: 'Resource Viewer'},
  ];
  tab = signal<string>('library');
}
