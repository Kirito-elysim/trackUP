import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { ProtectedRoute } from './ProtectedRoute';

const mockUseAuth = vi.fn();

vi.mock('../contexts/useAuth', () => ({
  useAuth: () => mockUseAuth(),
}));

function renderProtectedRoute() {
  return render(
    <MemoryRouter initialEntries={['/dashboard']}>
      <Routes>
        <Route path="/login" element={<div>Login page</div>} />
        <Route element={<ProtectedRoute />}>
          <Route path="/dashboard" element={<div>Dashboard content</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

describe('ProtectedRoute', () => {
  it('shows a loading state while the session is being resolved', () => {
    mockUseAuth.mockReturnValue({ token: null, loading: true });

    renderProtectedRoute();

    expect(screen.getByText('Chargement de la session...')).toBeInTheDocument();
    expect(screen.queryByText('Dashboard content')).not.toBeInTheDocument();
  });

  it('redirects to /login when there is no token', () => {
    mockUseAuth.mockReturnValue({ token: null, loading: false });

    renderProtectedRoute();

    expect(screen.getByText('Login page')).toBeInTheDocument();
  });

  it('redirects to /login when the token is expired', () => {
    const expiredPayload = btoa(JSON.stringify({ exp: Math.floor(Date.now() / 1000) - 3600 }));
    mockUseAuth.mockReturnValue({ token: `header.${expiredPayload}.sig`, loading: false });

    renderProtectedRoute();

    expect(screen.getByText('Login page')).toBeInTheDocument();
  });

  it('renders the nested route when the token is valid', () => {
    const validPayload = btoa(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + 3600 }));
    mockUseAuth.mockReturnValue({ token: `header.${validPayload}.sig`, loading: false });

    renderProtectedRoute();

    expect(screen.getByText('Dashboard content')).toBeInTheDocument();
  });
});
