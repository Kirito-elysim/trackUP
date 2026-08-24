import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  Calendar,
  CheckCircle2,
  Clock,
  FileText,
  Mail,
  MapPin,
  Send,
  ShieldAlert,
  StickyNote,
  User,
  XCircle,
} from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime } from '../lib/format';
import type { AbsenceDetail, AbsenceStatus } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { AbsAvatar, AbsStatusChip, AbsTypeChip } from '@/components/absences/badges';

const STATUS_LABEL: Record<AbsenceStatus, string> = {
  en_attente: 'En attente',
  justifiee: 'Justifiée',
  non_justifiee: 'Non justifiée',
  autre: 'Autre',
};

const ACTOR_CLASS: Record<string, string> = {
  Système: 'bg-abs-ink-100 text-abs-ink-600',
  Email: 'bg-abs-brand-100 text-abs-brand-700',
  Apprenant: 'bg-abs-accent-100 text-abs-accent-800',
  Admin: 'bg-abs-success-100 text-abs-success-700',
};

type WorkflowStep = {
  key: string;
  label: string;
  description: string;
  done: boolean;
  active: boolean;
  date: string | null;
  actor: 'Système' | 'Email' | 'Apprenant' | 'Admin';
};

function buildWorkflow(absence: AbsenceDetail): WorkflowStep[] {
  return [
    {
      key: 'detect',
      label: 'Détection',
      description: 'Absence détectée automatiquement (session passée sans émargement).',
      done: true,
      active: false,
      date: absence.detectedAt,
      actor: 'Système',
    },
    {
      key: 'notify',
      label: 'Notification',
      description: "Email automatique envoyé à l'apprenant avec lien sécurisé.",
      done: absence.notificationSentAt !== null,
      active: false,
      date: absence.notificationSentAt,
      actor: 'Email',
    },
    {
      key: 'respond',
      label: 'Réponse apprenant',
      description: 'Dépôt du justificatif via le lien sécurisé de l’email.',
      done: absence.justificationSubmittedAt !== null,
      active: absence.status === 'en_attente' && absence.justificationSubmittedAt === null,
      date: absence.justificationSubmittedAt,
      actor: 'Apprenant',
    },
    {
      key: 'validate',
      label: 'Validation admin',
      description: "L'admin valide ou rejette le justificatif.",
      done: absence.status === 'justifiee' || absence.status === 'non_justifiee' || absence.status === 'autre',
      active: absence.status === 'en_attente' && absence.justificationSubmittedAt !== null,
      date: absence.validatedAt,
      actor: 'Admin',
    },
  ];
}

export function AbsenceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { token } = useAuth();
  const navigate = useNavigate();
  const [absence, setAbsence] = useState<AbsenceDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!token || !id) return;

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const data = await apiRequest<AbsenceDetail>(`/api/admin/absences/${id}`, { token });
        if (!cancelled) {
          setAbsence(data);
          setNote(data.adminNote ?? '');
        }
      } catch (caught) {
        if (!cancelled) setError(caught instanceof ApiError ? caught.message : 'Absence introuvable.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [id, token]);

  const updateAbsence = async (changes: { status?: AbsenceStatus; adminNote?: string }) => {
    if (!token || !absence) return;

    setSaving(true);
    try {
      const updated = await apiRequest<AbsenceDetail>(`/api/admin/absences/${absence.id}`, {
        method: 'PATCH',
        token,
        body: changes,
      });
      setAbsence((current) => (current ? { ...current, ...updated, learner: current.learner, session: current.session } : current));
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Mise à jour impossible.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <p className="py-14 text-center text-sm text-muted-foreground">Chargement...</p>;
  }

  if (error || !absence) {
    return (
      <div className="flex flex-col items-center gap-4 py-20 text-center">
        <p className="text-sm text-muted-foreground">{error ?? 'Absence introuvable.'}</p>
        <Button onClick={() => navigate('/absences')}>Retour à la liste</Button>
      </div>
    );
  }

  const steps = buildWorkflow(absence);

  return (
    <section className="flex max-w-5xl flex-col gap-6">
      <button
        onClick={() => navigate('/absences')}
        className="-ml-2 flex w-fit items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-semibold text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft size={15} /> Retour aux absences
      </button>

      <Card>
        <CardContent className="flex flex-col gap-5 p-6 lg:flex-row lg:items-start lg:justify-between">
          <div className="flex gap-4">
            <AbsAvatar name={absence.learner.fullName} size="lg" />
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">#{absence.id}</span>
                <AbsStatusChip status={absence.status} />
                <AbsTypeChip type={absence.type} />
                {absence.learner.alertTriggered ? (
                  <span className="inline-flex w-fit animate-pulse items-center gap-1.5 rounded-full bg-abs-danger-100 px-2.5 py-1 text-xs font-medium text-abs-danger-800 ring-1 ring-abs-danger-200">
                    <ShieldAlert size={12} /> Alerte &middot; {absence.learner.consecutiveUnjustifiedMasterclassAbsences} abs.
                  </span>
                ) : null}
              </div>
              <h2 className="mt-1.5 font-display text-xl font-bold tracking-tight">{absence.session.title}</h2>
              <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1.5 text-sm text-muted-foreground">
                <span className="flex items-center gap-1.5"><User size={14} /> {absence.learner.fullName}</span>
                <span className="flex items-center gap-1.5"><Calendar size={14} /> {formatDateTime(absence.session.startAt)}</span>
                {absence.learner.email ? (
                  <span className="flex items-center gap-1.5"><MapPin size={14} /> {absence.learner.email}</span>
                ) : null}
              </div>
            </div>
          </div>

          <div className="flex shrink-0 gap-2">
            <Button disabled={saving} onClick={() => void updateAbsence({ status: 'justifiee' })}>
              <CheckCircle2 size={16} /> Valider
            </Button>
            <Button variant="outline" disabled={saving} onClick={() => void updateAbsence({ status: 'non_justifiee' })}>
              <XCircle size={16} /> Rejeter
            </Button>
          </div>
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div className="flex flex-col gap-5 lg:col-span-2">
          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Processus automatisé en 4 étapes</p>
                <h3 className="font-display text-base font-bold tracking-tight">Workflow justificatif</h3>
              </div>
              <ol className="relative flex flex-col gap-6 pl-1">
                <div className="absolute bottom-2 left-[17px] top-2 w-px bg-abs-ink-100" />
                {steps.map((step, index) => (
                  <li key={step.key} className="relative flex gap-4">
                    <div
                      className={`relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-card ${
                        step.done
                          ? 'bg-abs-success-500 text-white'
                          : step.active
                            ? 'animate-pulse bg-abs-warning-500 text-white'
                            : 'bg-abs-ink-100 text-abs-ink-400'
                      }`}
                    >
                      {step.done ? <CheckCircle2 size={18} /> : <Clock size={16} />}
                    </div>
                    <div className="pt-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm font-semibold">{index + 1}. {step.label}</p>
                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${ACTOR_CLASS[step.actor]}`}>{step.actor}</span>
                        {step.date ? <span className="text-xs text-muted-foreground">{formatDateTime(step.date)}</span> : null}
                      </div>
                      <p className="mt-1 text-sm text-muted-foreground">{step.description}</p>
                    </div>
                  </li>
                ))}
              </ol>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-4 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Document fourni par l'apprenant</p>
                <h3 className="font-display text-base font-bold tracking-tight">Justificatif déposé</h3>
              </div>
              {absence.justificationFileOriginalName ? (
                <div className="flex items-center gap-4 rounded-xl border border-abs-ink-100 bg-abs-ink-50/60 p-4">
                  <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-abs-ink-200 bg-card">
                    <FileText size={20} className="text-abs-danger-500" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{absence.justificationFileOriginalName}</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                      Déposé le {formatDateTime(absence.justificationSubmittedAt)}
                    </p>
                  </div>
                </div>
              ) : (
                <div className="flex flex-col items-center gap-2 py-8 text-center text-muted-foreground">
                  <Clock size={24} />
                  <p className="text-sm">Aucun justificatif déposé.</p>
                  {absence.status === 'en_attente' ? (
                    <p className="text-xs">Email envoyé, en attente de réponse de l&rsquo;apprenant.</p>
                  ) : null}
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-3 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Communication automatisée</p>
                <h3 className="font-display text-base font-bold tracking-tight">Email de notification envoyé</h3>
              </div>
              <div className="rounded-xl border border-abs-ink-100 bg-card p-4">
                <div className="flex items-center gap-2 border-b border-abs-ink-100 pb-3 text-xs text-muted-foreground">
                  <Mail size={14} className="text-abs-brand-500" /> À : {absence.learner.email ?? 'email indisponible'}
                </div>
                <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                  Bonjour {absence.learner.fullName.split(' ')[0]},<br />
                  <br />
                  Votre absence à la session « <strong className="text-foreground">{absence.session.title}</strong> »
                  {' '}du {formatDateTime(absence.session.startAt)} a été enregistrée. Merci de déposer votre justificatif
                  via le lien sécurisé, valable 14 jours.
                </p>
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="flex flex-col gap-5">
          <Card>
            <CardContent className="flex flex-col gap-3 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Changer manuellement</p>
                <h3 className="font-display text-base font-bold tracking-tight">Statut</h3>
              </div>
              <div className="flex flex-col gap-2">
                {(Object.keys(STATUS_LABEL) as AbsenceStatus[]).map((status) => (
                  <button
                    key={status}
                    disabled={saving}
                    onClick={() => void updateAbsence({ status })}
                    className={`flex items-center justify-between rounded-xl border px-3.5 py-2.5 text-sm transition-colors ${
                      absence.status === status
                        ? 'border-abs-brand-300 bg-abs-brand-50 font-semibold text-abs-brand-800'
                        : 'border-abs-ink-200 text-abs-ink-700 hover:border-abs-ink-300'
                    }`}
                  >
                    <span className="flex items-center gap-2">
                      <span className={`h-2 w-2 rounded-full ${
                        status === 'justifiee' ? 'bg-abs-success-500' : status === 'non_justifiee' ? 'bg-abs-danger-500' : status === 'autre' ? 'bg-abs-ink-400' : 'bg-abs-warning-500'
                      }`} />
                      {STATUS_LABEL[status]}
                    </span>
                    {absence.status === status ? <CheckCircle2 size={15} /> : null}
                  </button>
                ))}
              </div>
              {absence.validatedAt ? (
                <p className="text-xs text-muted-foreground">Validé le {formatDateTime(absence.validatedAt)}</p>
              ) : null}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-3 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Visible uniquement admin</p>
                <h3 className="font-display text-base font-bold tracking-tight">Note interne</h3>
              </div>
              <Textarea
                className="min-h-[100px]"
                placeholder="Ajouter une note (contexte, décision, suivi)..."
                value={note}
                onChange={(event) => setNote(event.target.value)}
              />
              <Button variant="outline" className="w-full" disabled={saving} onClick={() => void updateAbsence({ adminNote: note })}>
                <StickyNote size={15} /> Enregistrer la note
              </Button>
            </CardContent>
          </Card>

          {absence.learner.alertTriggered ? (
            <Card className="border-abs-danger-200 bg-abs-danger-50/70">
              <CardContent className="flex flex-col gap-2 p-5">
                <div className="flex items-center gap-2 text-abs-danger-700">
                  <ShieldAlert size={18} />
                  <p className="text-sm font-semibold">Alerte disciplinaire active</p>
                </div>
                <p className="text-sm text-abs-danger-600">
                  {absence.learner.consecutiveUnjustifiedMasterclassAbsences} absences injustifiées consécutives en
                  masterclass. Email envoyé à <strong>pedagogie@edup-bs.com</strong>.
                </p>
                <Button
                  className="mt-1 w-full bg-abs-danger-600 text-white hover:bg-abs-danger-700"
                  onClick={() => navigate('/absences/alertes')}
                >
                  <Send size={14} /> Gérer l&rsquo;alerte
                </Button>
              </CardContent>
            </Card>
          ) : null}

          <button
            onClick={() => navigate(`/learners/${absence.learner.id}`)}
            className="text-left"
          >
            <Card className="transition-all duration-200 hover:shadow-soft-hover">
              <CardContent className="flex items-center gap-3 p-4">
                <AbsAvatar name={absence.learner.fullName} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">Fiche apprenant</p>
                  <p className="truncate text-xs text-muted-foreground">{absence.learner.fullName}</p>
                </div>
              </CardContent>
            </Card>
          </button>
        </div>
      </div>
    </section>
  );
}
