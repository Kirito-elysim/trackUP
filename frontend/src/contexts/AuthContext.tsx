import {
  startTransition,
  useCallback,
  useEffect,
  useMemo,
  useState,
  type PropsWithChildren,
} from 'react';
import { apiRequest } from '../lib/api';
import type { AuthenticatedUser } from '../types/auth';
import { AuthContext, type AuthContextValue } from './auth-context';

const TOKEN_STORAGE_KEY = 'trackup.auth.token';

export function AuthProvider({ children }: PropsWithChildren) {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_STORAGE_KEY));
  const [user, setUser] = useState<AuthenticatedUser | null>(null);
  const [loading, setLoading] = useState(true);

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

  const logout = useCallback(() => {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
    startTransition(() => {
      setToken(null);
      setUser(null);
      setLoading(false);
    });
  }, []);

  const canAccess = useCallback(
    (featureCode: string) => Boolean(user?.isAdmin || user?.features.includes(featureCode)),
    [user],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      token,
      user,
      loading,
      login,
      logout,
      refreshUser,
      canAccess,
    }),
    [canAccess, loading, login, logout, refreshUser, token, user],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
