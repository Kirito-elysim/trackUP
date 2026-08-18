import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { AlertCircle, CheckCircle2, KeyRound, Loader2 } from 'lucide-react';
import { apiRequest, ApiError } from '../lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const MIN_PASSWORD_LENGTH = 8;

export function ResetPasswordPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';

  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);

    if (password.length < MIN_PASSWORD_LENGTH) {
      setError(`Le mot de passe doit contenir au moins ${MIN_PASSWORD_LENGTH} caractères.`);
      return;
    }

    if (password !== confirmPassword) {
      setError('Les mots de passe ne correspondent pas.');
      return;
    }

    setSubmitting(true);
    try {
      await apiRequest('/api/auth/reset-password', { method: 'POST', body: { token, password } });
      setDone(true);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Réinitialisation impossible. Merci de réessayer.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="grid min-h-screen place-items-center bg-background p-5">
      <section className="animate-rise-in w-full max-w-md rounded-2xl border border-border bg-card p-10 shadow-soft-hover">
        <p className="mb-2 text-xs uppercase tracking-[0.2em] text-muted-foreground">Accès sécurisé</p>
        <h2 className="font-display text-2xl font-extrabold tracking-tight">Nouveau mot de passe</h2>

        {!token ? (
          <div className="mt-8 flex flex-col gap-6">
            <div className="flex items-start gap-2.5 rounded-xl border border-destructive/20 bg-destructive/10 p-3.5 text-sm font-medium text-destructive">
              <AlertCircle size={17} className="mt-0.5 shrink-0" />
              <span>Ce lien de réinitialisation est invalide.</span>
            </div>
            <Link to="/forgot-password" className="text-sm font-semibold text-primary hover:underline">
              Demander un nouveau lien
            </Link>
          </div>
        ) : done ? (
          <div className="mt-8 flex flex-col gap-6">
            <div className="flex items-start gap-2.5 rounded-xl border border-success/20 bg-success/10 p-3.5 text-sm font-medium text-success">
              <CheckCircle2 size={17} className="mt-0.5 shrink-0" />
              <span>Votre mot de passe a été mis à jour.</span>
            </div>
            <Button size="lg" onClick={() => navigate('/login', { replace: true })}>
              Se connecter
            </Button>
          </div>
        ) : (
          <>
            <p className="mt-2 text-sm text-muted-foreground">Choisissez un nouveau mot de passe pour votre compte.</p>
            <form className="mt-8 flex flex-col gap-5" onSubmit={handleSubmit}>
              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Nouveau mot de passe</span>
                <Input
                  type="password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  disabled={submitting}
                  autoComplete="new-password"
                  autoFocus
                  required
                />
              </label>

              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Confirmer le mot de passe</span>
                <Input
                  type="password"
                  value={confirmPassword}
                  onChange={(event) => setConfirmPassword(event.target.value)}
                  disabled={submitting}
                  autoComplete="new-password"
                  required
                />
              </label>

              {error ? (
                <div
                  role="alert"
                  className="flex items-start gap-2.5 rounded-xl border border-destructive/20 bg-destructive/10 p-3.5 text-sm font-medium text-destructive"
                >
                  <AlertCircle size={17} className="mt-0.5 shrink-0" />
                  <span>{error}</span>
                </div>
              ) : null}

              <Button disabled={submitting} type="submit" size="lg">
                {submitting ? <Loader2 size={16} className="animate-spin" /> : <KeyRound size={16} />}
                {submitting ? 'Mise à jour...' : 'Réinitialiser le mot de passe'}
              </Button>
            </form>
          </>
        )}
      </section>
    </div>
  );
}
