import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../contexts/useAuth';
import { isTokenExpired } from '../lib/jwt';

export function ProtectedRoute() {
  const { token, loading, sessionExpired } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="grid min-h-screen place-items-center bg-background">
        <p className="flex items-center gap-2.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">
          <span className="h-2 w-2 animate-pulse rounded-full bg-primary" aria-hidden="true" />
          Chargement de la session...
        </p>
      </div>
    );
  }

  if (!token || isTokenExpired(token)) {
    // sessionExpired (not the local token, which AuthContext already scrubs from
    // localStorage as soon as it's found to be expired) is the source of truth for
    // whether the user was previously logged in and should see an explanation here.
    const reason = sessionExpired ? 'expired' : undefined;
    return <Navigate to="/login" replace state={{ from: location, reason }} />;
  }

  return <Outlet />;
}
