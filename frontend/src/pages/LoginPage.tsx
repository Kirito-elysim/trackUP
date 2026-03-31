import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/useAuth';
import { ApiError } from '../lib/api';

export function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { login } = useAuth();
  const [email, setEmail] = useState('admin@trackup.local');
  const [password, setPassword] = useState('TrackUp123!');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const from = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname ?? '/dashboard';

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await login(email, password);
      navigate(from, { replace: true });
    } catch (caught) {
      if (caught instanceof ApiError) {
        setError(caught.message);
      } else {
        setError('Connexion impossible.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="login-shell">
      <section className="login-layout">
        <div className="login-showcase">
          <div className="brand-block">
            <img alt="TrackUp" className="brand-logo brand-logo-hero" src="/trackup-logo.png" />
            <p className="eyebrow">Powered by Ed'Up spirit</p>
            <h1>Piloter les heures. Clarifier la conformité.</h1>
            <p className="muted">
              Console unifiée pour centraliser les données Rise Up, suivre les apprenants et produire des exports lisibles.
            </p>
          </div>

          <div className="showcase-grid">
            <article className="showcase-card">
              <span className="showcase-kicker">Vision globale</span>
              <strong>Temps total, progression et masterclass dans une seule lecture.</strong>
            </article>
            <article className="showcase-card">
              <span className="showcase-kicker">Conformité</span>
              <strong>Exports propres, signatures de présence et détails à la demande.</strong>
            </article>
          </div>
        </div>

        <div className="login-card">
          <div>
            <p className="eyebrow eyebrow-dark">Accès sécurisé</p>
            <h2>Connexion admin</h2>
            <p className="muted">Le menu visible dépend du rôle et des features attribuées à l’utilisateur connecté.</p>
          </div>

          <form className="stack" onSubmit={handleSubmit}>
            <label className="field">
              <span>Email</span>
              <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
            </label>

            <label className="field">
              <span>Mot de passe</span>
              <input type="password" value={password} onChange={(event) => setPassword(event.target.value)} />
            </label>

            {error ? <p className="error-text">{error}</p> : null}

            <button className="primary-button" disabled={submitting} type="submit">
              {submitting ? 'Connexion...' : 'Se connecter'}
            </button>
          </form>
        </div>
      </section>
    </div>
  );
}
