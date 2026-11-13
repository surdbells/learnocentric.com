import { RenderMode, ServerRoute } from '@angular/ssr';

/**
 * Signed-in areas render in the browser: the session token lives in
 * localStorage, which the server can't see, so server-rendering them only
 * ever produced a redirect to /login (and hydration mismatches on return).
 * Public pages (landing, sign-in, register) are still server-rendered.
 */
const PORTALS = ['admin', 'academy', 'teacher', 'student', 'parent', 'super-admin', 'notifications'];

export const serverRoutes: ServerRoute[] = [
  ...PORTALS.flatMap((area): ServerRoute[] => [
    { path: area, renderMode: RenderMode.Client },
    { path: `${area}/**`, renderMode: RenderMode.Client },
  ]),
  { path: 'account/**', renderMode: RenderMode.Client },
  { path: 'billing/**', renderMode: RenderMode.Client },
  {
    path: '**',
    renderMode: RenderMode.Server
  }
];
