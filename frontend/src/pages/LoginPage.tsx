import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Loader2, TimerReset } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { ApiError } from '../lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormError } from '@/components/ui/form-error';

export function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { login, sessionExpired } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const from = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname ?? '/dashboard';
  const showSessionExpired = !error && (sessionExpired || (location.state as { reason?: string } | null)?.reason === 'expired');

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
        setError('Connexion impossible. Merci de réessayer.');
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

          {showSessionExpired ? (
            <div className="flex items-start gap-2.5 rounded-xl border border-border bg-muted/60 p-3.5 text-sm text-muted-foreground">
              <TimerReset size={17} className="mt-0.5 shrink-0 text-foreground/70" />
              <span>Votre session a expiré. Merci de vous reconnecter.</span>
            </div>
          ) : null}

          <form className="flex flex-col gap-5" noValidate onSubmit={handleSubmit}>
            <label className="flex flex-col gap-2">
              <span className="text-sm font-semibold">Email</span>
              <Input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                disabled={submitting}
                autoComplete="email"
                required
              />
            </label>

            <label className="flex flex-col gap-2">
              <div className="flex items-center justify-between">
                <span className="text-sm font-semibold">Mot de passe</span>
                <Link to="/forgot-password" className="text-xs font-semibold text-primary hover:underline">
                  Mot de passe oublié ?
                </Link>
              </div>
              <Input
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                disabled={submitting}
                autoComplete="current-password"
                required
              />
            </label>

            <FormError message={error} />

            <Button disabled={submitting} type="submit" size="lg">
              {submitting ? <Loader2 size={16} className="animate-spin" /> : null}
              {submitting ? 'Connexion...' : 'Se connecter'}
            </Button>
          </form>
        </div>
      </section>
    </div>
  );
}
