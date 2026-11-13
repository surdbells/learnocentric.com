import { RenderMode, ServerRoute } from '@angular/ssr';

export const serverRoutes: ServerRoute[] = [
  // Session-only pages (the token lives in localStorage), so render in the browser.
  { path: 'account/**', renderMode: RenderMode.Client },
  { path: 'billing/callback', renderMode: RenderMode.Client },
  {
    path: '**',
    renderMode: RenderMode.Server
  }
];
