/**
 * Where each role lands after signing in. Custom roles arrive here already
 * resolved to their base system role (the API sends the effective role code).
 */
const PORTALS: Record<string, string> = {
  super_admin: '/super-admin/main',
  school_admin: '/admin/main',
  tutor_admin: '/academy/main',
  academic_lead: '/admin/main',
  teacher: '/teacher/main',
  student: '/student/main',
  parent: '/parent/main',
};

export function portalFor(role: string | null | undefined): string | null {
  return role ? PORTALS[role] ?? null : null;
}

/**
 * URL root of a role's area, e.g. '/academy' for a tutor admin. Shared admin
 * screens build their links from this instead of hard-coding '/admin'.
 */
export function roleRoot(role: string | null | undefined): string {
  const portal = portalFor(role);
  return portal ? portal.replace(/\/main$/, '') : '/admin';
}
