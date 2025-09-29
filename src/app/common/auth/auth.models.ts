export interface AuthUser {
  id: string | number;
  name?: string;
  email?: string;
  roles?: string[];
  [key: string]: any;
}

export interface AuthResponse {
  // Adjust fields according to your backend response
  token?: string; // JWT if backend returns in body
  user?: AuthUser; // user profile
  // Support common variants
  accessToken?: string;
  data?: { token?: string; user?: AuthUser };
}

export interface AuthSession {
  token: string | null; // JWT if readable. If backend uses HttpOnly cookies, this can be null
  user: AuthUser | null;
  isAuthenticated: boolean;
}
