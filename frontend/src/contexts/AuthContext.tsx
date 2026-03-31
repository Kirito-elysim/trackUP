import {
  createContext,
  startTransition,
  useContext,
  useEffect,
  useMemo,
  useState,
  type PropsWithChildren,
} from 'react';
import { apiRequest } from '../lib/api';
import type { AuthenticatedUser } from '../types/auth';

type AuthContextValue = {
  token: string | null;
  user: AuthenticatedUser | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  refreshUser: () => Promise<void>;
  canAccess: (featureCode: string) => boolean;
};

const AuthContext = createContext<AuthContextValue | null>(null);
const TOKEN_STORAGE_KEY = 'trackup.auth.token';

export function AuthProvider({ children }: PropsWithChildren) {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem(TOKEN_STORAGE_KEY));
  const [user, setUser] = useState<AuthenticatedUser | null>(null);
  const [loading, setLoading] = useState(true);

  const refreshUser = async () => {
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
  };

  useEffect(() => {
    void refreshUser();
  }, [token]);

  const login = async (email: string, password: string) => {
    setLoading(true);
    const response = await apiRequest<{ token: string }>('/api/auth/login', {
      method: 'POST',
      body: { email, password },
    });

    localStorage.setItem(TOKEN_STORAGE_KEY, response.token);
    startTransition(() => {
      setToken(response.token);
    });
  };

  const logout = () => {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
    startTransition(() => {
      setToken(null);
      setUser(null);
    });
  };

  const value = useMemo<AuthContextValue>(
    () => ({
      token,
      user,
      loading,
      login,
      logout,
      refreshUser,
      canAccess: (featureCode: string) => Boolean(user?.isAdmin || user?.features.includes(featureCode)),
    }),
    [loading, token, user],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }

  return context;
}
