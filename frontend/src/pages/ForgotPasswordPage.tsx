import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, Loader2, Mail } from 'lucide-react';
import { apiRequest, ApiError } from '../lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormError } from '@/components/ui/form-error';

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await apiRequest('/api/auth/forgot-password', { method: 'POST', body: { email } });
      setSent(true);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Envoi impossible. Merci de réessayer.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="grid min-h-screen place-items-center bg-background p-5">
      <section className="animate-rise-in w-full max-w-md rounded-2xl border border-border bg-card p-10 shadow-soft-hover">
        <p className="mb-2 text-xs uppercase tracking-[0.2em] text-muted-foreground">Accès sécurisé</p>
        <h2 className="font-display text-2xl font-extrabold tracking-tight">Mot de passe oublié</h2>
        <p className="mt-2 text-sm text-muted-foreground">
          Indiquez votre email : si un compte existe, vous recevrez un lien pour choisir un nouveau mot de passe.
        </p>

        {sent ? (
          <div className="mt-8 flex flex-col gap-6">
            <div className="flex items-start gap-2.5 rounded-xl border border-success/20 bg-success/10 p-3.5 text-sm font-medium text-success">
              <CheckCircle2 size={17} className="mt-0.5 shrink-0" />
              <span>Si un compte existe avec cette adresse, un email de réinitialisation vient d&rsquo;être envoyé.</span>
            </div>
            <Link
              to="/login"
              className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline"
            >
              <ArrowLeft size={15} />
              Retour à la connexion
            </Link>
          </div>
        ) : (
          <form className="mt-8 flex flex-col gap-5" noValidate onSubmit={handleSubmit}>
            <label className="flex flex-col gap-2">
              <span className="text-sm font-semibold">Email</span>
              <Input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                disabled={submitting}
                autoComplete="email"
                autoFocus
                required
              />
            </label>

            <FormError message={error} />

            <Button disabled={submitting} type="submit" size="lg">
              {submitting ? <Loader2 size={16} className="animate-spin" /> : <Mail size={16} />}
              {submitting ? 'Envoi...' : 'Envoyer le lien'}
            </Button>

            <Link
              to="/login"
              className="inline-flex items-center gap-1.5 text-sm font-semibold text-muted-foreground hover:text-primary"
            >
              <ArrowLeft size={15} />
              Retour à la connexion
            </Link>
          </form>
        )}
      </section>
    </div>
  );
}
