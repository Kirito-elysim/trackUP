import { useState } from 'react';
import { AlertCircle, CheckCircle2, KeyRound, Loader2 } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const MIN_PASSWORD_LENGTH = 8;

export function ProfilePage() {
  const { user, token } = useAuth();
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const resetForm = () => {
    setCurrentPassword('');
    setNewPassword('');
    setConfirmPassword('');
  };

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setSuccess(false);

    if (newPassword.length < MIN_PASSWORD_LENGTH) {
      setError(`Le nouveau mot de passe doit contenir au moins ${MIN_PASSWORD_LENGTH} caractères.`);
      return;
    }

    if (newPassword !== confirmPassword) {
      setError('Les nouveaux mots de passe ne correspondent pas.');
      return;
    }

    setSubmitting(true);
    try {
      await apiRequest('/api/me/password', {
        method: 'PUT',
        token,
        body: { currentPassword, newPassword },
      });
      setSuccess(true);
      resetForm();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Mise à jour impossible. Merci de réessayer.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <section className="flex flex-col gap-8">
      <div>
        <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Mon compte</p>
        <h2 className="font-display text-3xl font-extrabold tracking-tight">Profil</h2>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[0.9fr_1.1fr]">
        <Card className="animate-rise-in">
          <CardContent className="flex flex-col gap-4 p-6">
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-brand text-base font-bold text-white">
              {user?.fullName?.[0]?.toUpperCase() ?? '?'}
            </span>
            <div>
              <p className="text-lg font-bold tracking-tight">{user?.fullName}</p>
              <p className="text-sm text-muted-foreground">{user?.email}</p>
            </div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">
              {user?.roles.map((role) => role.name).join(', ') || 'Utilisateur'}
            </p>
          </CardContent>
        </Card>

        <Card className="animate-rise-in" style={{ animationDelay: '80ms' }}>
          <CardContent className="flex flex-col gap-5 p-6">
            <div>
              <h3 className="font-display text-lg font-bold tracking-tight">Changer de mot de passe</h3>
              <p className="mt-1 text-sm text-muted-foreground">
                Votre mot de passe actuel vous sera demandé pour confirmer le changement.
              </p>
            </div>

            <form className="flex flex-col gap-5" onSubmit={handleSubmit}>
              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Mot de passe actuel</span>
                <Input
                  type="password"
                  value={currentPassword}
                  onChange={(event) => setCurrentPassword(event.target.value)}
                  disabled={submitting}
                  autoComplete="current-password"
                  required
                />
              </label>

              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Nouveau mot de passe</span>
                <Input
                  type="password"
                  value={newPassword}
                  onChange={(event) => setNewPassword(event.target.value)}
                  disabled={submitting}
                  autoComplete="new-password"
                  required
                />
              </label>

              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Confirmer le nouveau mot de passe</span>
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

              {success ? (
                <div className="flex items-start gap-2.5 rounded-xl border border-success/20 bg-success/10 p-3.5 text-sm font-medium text-success">
                  <CheckCircle2 size={17} className="mt-0.5 shrink-0" />
                  <span>Votre mot de passe a été mis à jour.</span>
                </div>
              ) : null}

              <Button disabled={submitting} type="submit" size="lg" className="w-fit">
                {submitting ? <Loader2 size={16} className="animate-spin" /> : <KeyRound size={16} />}
                {submitting ? 'Mise à jour...' : 'Mettre à jour le mot de passe'}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </section>
  );
}
