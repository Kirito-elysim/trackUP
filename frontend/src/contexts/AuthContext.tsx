import {
  startTransition,
  useCallback,
  useEffect,
  useMemo,
  useState,
  type PropsWithChildren,
} from 'react';
import { apiRequest, setUnauthorizedHandler } from '../lib/api';
import { isTokenExpired } from '../lib/jwt';
import type { AuthenticatedUser } from '../types/auth';
import { AuthContext, type AuthContextValue } from './auth-context';

const TOKEN_STORAGE_KEY = 'trackup.auth.token';

function readStoredToken(): { token: string | null; wasExpired: boolean } {
  const stored = localStorage.getItem(TOKEN_STORAGE_KEY);

  if (stored && isTokenExpired(stored)) {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
    return { token: null, wasExpired: true };
  }

  return { token: stored, wasExpired: false };
}

export function AuthProvider({ children }: PropsWithChildren) {
  // Read once and reuse for both fields below — calling readStoredToken() twice would
  // have the second call miss the expired token, since the first call already removed
  // it from localStorage.
  const [initial] = useState(readStoredToken);
  const [token, setToken] = useState<string | null>(initial.token);
  const [user, setUser] = useState<AuthenticatedUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [sessionExpired, setSessionExpired] = useState(initial.wasExpired);

  const refreshUser = useCallback(async () => {
    if (!token) {
      startTransition(() => {
        setUser(null);
        setLoading(false);
      });
      return;
    }

    try {
      const currentUser = await apiRequest<AuthenticatedUser>('/api/me', { token });
      startTransition(() => {
        setUser(currentUser);
        setLoading(false);
      });
    } catch {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
      startTransition(() => {
        setToken(null);
        setUser(null);
        setLoading(false);
      });
    }
  }, [token]);

  useEffect(() => {
    void refreshUser();
  }, [refreshUser]);

  const login = useCallback(async (email: string, password: string) => {
    setLoading(true);
    setSessionExpired(false);
    try {
      const response = await apiRequest<{ token: string }>('/api/auth/login', {
        method: 'POST',
        body: { email, password },
      });

      localStorage.setItem(TOKEN_STORAGE_KEY, response.token);
      startTransition(() => {
        setToken(response.token);
      });
    } catch (error) {
      startTransition(() => {
        setLoading(false);
      });

      throw error;
    }
  }, []);

  const logout = useCallback((reason?: 'expired') => {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
    startTransition(() => {
      setToken(null);
      setUser(null);
      setLoading(false);
      setSessionExpired(reason === 'expired');
    });
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => logout('expired'));
    return () => setUnauthorizedHandler(null);
  }, [logout]);

  const canAccess = useCallback(
    (featureCode: string) => Boolean(user?.isAdmin || user?.features.includes(featureCode)),
    [user],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      token,
      user,
      loading,
      sessionExpired,
      login,
      logout,
      refreshUser,
      canAccess,
    }),
    [canAccess, loading, login, logout, refreshUser, sessionExpired, token, user],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
