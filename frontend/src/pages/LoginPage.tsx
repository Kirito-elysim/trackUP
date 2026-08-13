import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/useAuth';
import { ApiError } from '../lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

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
    <div className="grid min-h-screen place-items-center bg-background p-5">
      <section className="animate-rise-in grid w-full max-w-5xl grid-cols-1 overflow-hidden rounded-2xl border border-border bg-card shadow-soft-hover lg:grid-cols-[1.15fr_0.85fr]">
        <div className="flex flex-col items-center justify-center gap-10 bg-[#457dc5] p-8 text-white lg:items-stretch lg:justify-between lg:p-10">
          <div className="flex flex-col items-center gap-5 lg:items-stretch">
            <img alt="TrackUp" className="h-28 w-auto lg:h-40 lg:self-start" src="/trackup-logo.png" />
            <div className="hidden flex-col gap-5 lg:flex">
              <p className="text-xs uppercase tracking-[0.24em] text-white/50">Powered by Elysium solution</p>
              <h1 className="font-display text-4xl font-extrabold leading-[1.05] tracking-tight">
                Piloter les heures.
                <br />
                Clarifier la conformité.
              </h1>
              <p className="max-w-[42ch] text-sm text-white/65">
                Console unifiée pour centraliser les données Rise Up, suivre les apprenants et produire des exports
                lisibles.
              </p>
            </div>
          </div>

          <div className="hidden gap-3 lg:grid lg:grid-cols-2">
            <article className="rounded-xl border border-white/10 bg-white/[0.06] p-4">
              <span className="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-white">
                Vision globale
              </span>
              <strong className="mt-2 block text-sm font-semibold leading-snug text-white/90">
                Temps total, progression et masterclass dans une seule lecture.
              </strong>
            </article>
            <article className="rounded-xl border border-white/10 bg-white/[0.06] p-4">
              <span className="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-white">
                Conformité
              </span>
              <strong className="mt-2 block text-sm font-semibold leading-snug text-white/90">
                Exports propres, signatures de présence et détails à la demande.
              </strong>
            </article>
          </div>
        </div>

        <div className="flex flex-col justify-center gap-8 p-10">
          <div>
            <p className="mb-2 text-xs uppercase tracking-[0.2em] text-muted-foreground">Accès sécurisé</p>
            <h2 className="font-display text-2xl font-extrabold tracking-tight">Connexion admin</h2>
            <p className="mt-2 text-sm text-muted-foreground">
              Le menu visible dépend du rôle et des features attribuées à l&rsquo;utilisateur connecté.
            </p>
          </div>

          <form className="flex flex-col gap-5" onSubmit={handleSubmit}>
            <label className="flex flex-col gap-2">
              <span className="text-sm font-semibold">Email</span>
              <Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
            </label>

            <label className="flex flex-col gap-2">
              <span className="text-sm font-semibold">Mot de passe</span>
              <Input
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
              />
            </label>

            {error ? <p className="text-sm font-medium text-destructive">{error}</p> : null}

            <Button disabled={submitting} type="submit" size="lg">
              {submitting ? 'Connexion...' : 'Se connecter'}
            </Button>
          </form>
        </div>
      </section>
    </div>
  );
}
