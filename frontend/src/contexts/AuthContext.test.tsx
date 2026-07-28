import { act, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider } from './AuthContext';
import { useAuth } from './useAuth';
import { apiRequest, setUnauthorizedHandler } from '../lib/api';

vi.mock('../lib/api', () => ({
  apiRequest: vi.fn(),
  setUnauthorizedHandler: vi.fn(),
}));

const TOKEN_STORAGE_KEY = 'trackup.auth.token';

function makeToken(expOffsetSeconds: number): string {
  const payload = btoa(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + expOffsetSeconds }));
  return `header.${payload}.signature`;
}

function TestConsumer() {
  const { token, user, loading, login, logout } = useAuth();

  return (
    <div>
      <span data-testid="loading">{String(loading)}</span>
      <span data-testid="token">{token ?? 'none'}</span>
      <span data-testid="user">{user?.email ?? 'none'}</span>
      <button type="button" onClick={() => void login('admin@trackup.local', 'TrackUp123!')}>
        login
      </button>
      <button type="button" onClick={() => logout()}>
        logout
      </button>
    </div>
  );
}

function renderAuthProvider() {
  return render(
    <AuthProvider>
      <TestConsumer />
    </AuthProvider>,
  );
}

describe('AuthProvider', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.mocked(apiRequest).mockReset();
    vi.mocked(setUnauthorizedHandler).mockReset();
  });

  it('starts with no user when there is no stored token', async () => {
    renderAuthProvider();

    await waitFor(() => expect(screen.getByTestId('loading').textContent).toBe('false'));
    expect(screen.getByTestId('token').textContent).toBe('none');
    expect(screen.getByTestId('user').textContent).toBe('none');
  });

  it('discards an already-expired token found in localStorage on mount', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, makeToken(-3600));

    renderAuthProvider();

    await waitFor(() => expect(screen.getByTestId('loading').textContent).toBe('false'));
    expect(screen.getByTestId('token').textContent).toBe('none');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('logs in, persists the token, and loads the user via /api/me', async () => {
    vi.mocked(apiRequest).mockImplementation(async (path: string) => {
      if (path === '/api/auth/login') {
        return { token: makeToken(3600) } as never;
      }
      if (path === '/api/me') {
        return { email: 'admin@trackup.local', isAdmin: true, features: [] } as never;
      }
      throw new Error(`unexpected path: ${path}`);
    });

    renderAuthProvider();
    await waitFor(() => expect(screen.getByTestId('loading').textContent).toBe('false'));

    act(() => screen.getByText('login').click());

    await waitFor(() => expect(screen.getByTestId('user').textContent).toBe('admin@trackup.local'));
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).not.toBeNull();
  });

  it('clears the token, user, and storage on logout', async () => {
    vi.mocked(apiRequest).mockImplementation(async (path: string) => {
      if (path === '/api/auth/login') {
        return { token: makeToken(3600) } as never;
      }
      if (path === '/api/me') {
        return { email: 'admin@trackup.local', isAdmin: true, features: [] } as never;
      }
      throw new Error(`unexpected path: ${path}`);
    });

    renderAuthProvider();
    await waitFor(() => expect(screen.getByTestId('loading').textContent).toBe('false'));
    act(() => screen.getByText('login').click());
    await waitFor(() => expect(screen.getByTestId('user').textContent).toBe('admin@trackup.local'));

    act(() => screen.getByText('logout').click());

    expect(screen.getByTestId('token').textContent).toBe('none');
    expect(screen.getByTestId('user').textContent).toBe('none');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('logs out if /api/me rejects the stored token (e.g. revoked server-side)', async () => {
    localStorage.setItem(TOKEN_STORAGE_KEY, makeToken(3600));
    vi.mocked(apiRequest).mockRejectedValue(new Error('401'));

    renderAuthProvider();

    await waitFor(() => expect(screen.getByTestId('loading').textContent).toBe('false'));
    expect(screen.getByTestId('token').textContent).toBe('none');
    expect(localStorage.getItem(TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('registers a handler with setUnauthorizedHandler that logs the user out', async () => {
    vi.mocked(apiRequest).mockImplementation(async (path: string) => {
      if (path === '/api/auth/login') {
        return { token: makeToken(3600) } as never;
      }
      if (path === '/api/me') {
        return { email: 'admin@trackup.local', isAdmin: true, features: [] } as never;
      }
      throw new Error(`unexpected path: ${path}`);
    });

    renderAuthProvider();
    await waitFor(() => expect(screen.getByTestId('loading').textContent).toBe('false'));
    act(() => screen.getByText('login').click());
    await waitFor(() => expect(screen.getByTestId('user').textContent).toBe('admin@trackup.local'));

    expect(setUnauthorizedHandler).toHaveBeenCalled();
    const registeredHandler = vi.mocked(setUnauthorizedHandler).mock.calls.at(-1)?.[0];
    expect(registeredHandler).toBeTypeOf('function');

    act(() => registeredHandler?.());

    expect(screen.getByTestId('token').textContent).toBe('none');
    expect(screen.getByTestId('user').textContent).toBe('none');
  });
});
