export interface AuthUser {
  id: string | number;
  firstName?: string;
  lastName?: string;
  email?: string;
  institutionId: string;
  role: 'school_admin' | 'teacher' | 'student' | 'tutor_admin' | 'parent' | 'super_admin';
  className?: string;
  sections?: string;
  gradeLevel?: string;
  profileImageUrl?: string;
  classId?:string;
  [key: string]: any;
}

export interface AuthResponse {
  token?: string; // JWT if backend returns in body
  user: AuthUser; // user profile
  // Support common variants
  accessToken?: string;
  data?: { token?: string; user?: AuthUser };
}

export interface AuthSession {
  token: string | null; // JWT if readable. If backend uses HttpOnly cookies, this can be null
  user: AuthUser | null;
  isAuthenticated: boolean;
}
