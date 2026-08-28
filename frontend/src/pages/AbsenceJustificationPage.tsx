import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Eye, FileUp, Loader2, RefreshCw } from 'lucide-react';
import { apiRequest, apiUrl, ApiError } from '../lib/api';
import { formatDateTime } from '../lib/format';
import { Button } from '@/components/ui/button';
import { FormError } from '@/components/ui/form-error';

const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

type JustificationStatus = {
  sessionLabel: string;
  sessionStartAt: string | null;
  alreadySubmitted: boolean;
  fileOriginalName: string | null;
  submittedAt: string | null;
  daysRemaining: number | null;
};

export function AbsenceJustificationPage() {
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';

  const [status, setStatus] = useState<JustificationStatus | null>(null);
  const [statusLoading, setStatusLoading] = useState(true);
  const [statusError, setStatusError] = useState<string | null>(null);

  const [showForm, setShowForm] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    if (!token) {
      setStatusLoading(false);
      return;
    }

    let cancelled = false;

    const load = async () => {
      setStatusLoading(true);
      try {
        const data = await apiRequest<JustificationStatus>(`/api/absences/justification?token=${encodeURIComponent(token)}`);
        if (!cancelled) setStatus(data);
      } catch (caught) {
        if (!cancelled) setStatusError(caught instanceof ApiError ? caught.message : 'Ce lien de dépôt est invalide.');
      } finally {
        if (!cancelled) setStatusLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [token]);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);

    if (!file) {
      setError('Merci de sélectionner un fichier.');
      return;
    }

    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!ALLOWED_EXTENSIONS.includes(extension)) {
      setError('Le fichier doit être au format PDF, JPG ou PNG.');
      return;
    }

    if (file.size > MAX_FILE_SIZE_BYTES) {
      setError('Le fichier dépasse la taille maximale autorisée (10 Mo).');
      return;
    }

    setSubmitting(true);
    try {
      const formData = new FormData();
      formData.append('token', token);
      formData.append('file', file);

      const response = await fetch(apiUrl('/api/absences/justification'), {
        method: 'POST',
        body: formData,
      });
      const payload = await response.json().catch(() => null);

      if (!response.ok) {
        throw new ApiError(payload?.message ?? 'Envoi impossible. Merci de réessayer.', response.status);
      }

      setDone(true);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Envoi impossible. Merci de réessayer.');
    } finally {
      setSubmitting(false);
    }
  };

  const fileViewUrl = apiUrl(`/api/absences/justification/file?token=${encodeURIComponent(token)}`);
  const invalidToken = !token || statusError !== null;

  return (
    <div className="grid min-h-screen place-items-center bg-background p-5">
      <section className="animate-rise-in w-full max-w-md rounded-2xl border border-border bg-card p-10 shadow-soft-hover">
        <p className="mb-2 text-xs uppercase tracking-[0.2em] text-muted-foreground">Absences</p>
        <h2 className="font-display text-2xl font-extrabold tracking-tight">Dépôt de justificatif</h2>

        {statusLoading ? (
          <p className="mt-8 text-center text-sm text-muted-foreground">Chargement...</p>
        ) : invalidToken ? (
          <div className="mt-8 flex flex-col gap-6">
            <FormError message={statusError ?? 'Ce lien de dépôt de justificatif est invalide.'} />
          </div>
        ) : done ? (
          <div className="mt-8 flex flex-col gap-6">
            <div className="flex items-start gap-2.5 rounded-xl border border-success/20 bg-success/10 p-3.5 text-sm font-medium text-success">
              <CheckCircle2 size={17} className="mt-0.5 shrink-0" />
              <span>Votre justificatif a bien été transmis. Il sera examiné par l&rsquo;équipe pédagogique.</span>
            </div>
          </div>
        ) : status?.alreadySubmitted && !showForm ? (
          <div className="mt-8 flex flex-col gap-6">
            <p className="text-sm text-muted-foreground">
              Absence à la session « <strong className="text-foreground">{status.sessionLabel}</strong> »
              {status.sessionStartAt ? ` du ${formatDateTime(status.sessionStartAt)}` : ''}.
              {status.daysRemaining !== null ? (
                <>
                  {' '}
                  Ce lien reste valable encore <strong className="text-foreground">{status.daysRemaining} jour{status.daysRemaining > 1 ? 's' : ''}</strong>.
                </>
              ) : null}
            </p>
            <div className="rounded-xl border border-border bg-muted/40 p-4">
              <p className="text-xs uppercase tracking-wide text-muted-foreground">Justificatif déjà transmis</p>
              <p className="mt-1 truncate font-medium">{status.fileOriginalName}</p>
              <p className="mt-0.5 text-xs text-muted-foreground">Déposé le {formatDateTime(status.submittedAt)}</p>
              <div className="mt-3 flex flex-wrap gap-2">
                <a
                  href={fileViewUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-semibold text-foreground hover:bg-muted"
                >
                  <Eye size={14} /> Voir le fichier
                </a>
                <button
                  type="button"
                  onClick={() => setShowForm(true)}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-semibold text-foreground hover:bg-muted"
                >
                  <RefreshCw size={14} /> Remplacer
                </button>
              </div>
            </div>
            <Link to="/login" className="text-center text-sm font-semibold text-muted-foreground hover:text-primary">
              Retour à la connexion
            </Link>
          </div>
        ) : (
          <>
            <p className="mt-2 text-sm text-muted-foreground">
              {status?.alreadySubmitted
                ? 'Déposez un nouveau fichier pour remplacer votre justificatif précédent (PDF, JPG ou PNG, 10 Mo maximum).'
                : 'Déposez votre justificatif d’absence (PDF, JPG ou PNG, 10 Mo maximum).'}
              {status?.daysRemaining !== null && status?.daysRemaining !== undefined ? (
                <> Encore <strong className="text-foreground">{status.daysRemaining} jour{status.daysRemaining > 1 ? 's' : ''}</strong> pour le faire.</>
              ) : null}
            </p>
            <form className="mt-8 flex flex-col gap-5" noValidate onSubmit={handleSubmit}>
              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Justificatif</span>
                <input
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png"
                  onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                  disabled={submitting}
                  className="rounded-lg border border-border bg-background px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-primary-foreground"
                  required
                />
              </label>

              <FormError message={error} />

              <Button disabled={submitting} type="submit" size="lg">
                {submitting ? <Loader2 size={16} className="animate-spin" /> : <FileUp size={16} />}
                {submitting ? 'Envoi...' : status?.alreadySubmitted ? 'Remplacer le justificatif' : 'Envoyer le justificatif'}
              </Button>

              {status?.alreadySubmitted ? (
                <button
                  type="button"
                  onClick={() => setShowForm(false)}
                  className="text-center text-sm font-semibold text-muted-foreground hover:text-primary"
                >
                  Annuler
                </button>
              ) : (
                <Link to="/login" className="text-center text-sm font-semibold text-muted-foreground hover:text-primary">
                  Retour à la connexion
                </Link>
              )}
            </form>
          </>
        )}
      </section>
    </div>
  );
}
