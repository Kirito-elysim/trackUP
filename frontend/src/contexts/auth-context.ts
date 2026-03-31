import { createContext } from 'react';
import type { AuthenticatedUser } from '../types/auth';

export type AuthContextValue = {
  token: string | null;
  user: AuthenticatedUser | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  refreshUser: () => Promise<void>;
  canAccess: (featureCode: string) => boolean;
};

export const AuthContext = createContext<AuthContextValue | null>(null);
