import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, CheckCircle2, ChevronRight, Mail, RotateCcw, Send, ShieldAlert } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime } from '../lib/format';
import type { AbsenceAlertsPayload } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { AbsAvatar, AbsStatusChip } from '@/components/absences/badges';

export function AbsenceAlertsPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [payload, setPayload] = useState<AbsenceAlertsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyLearnerId, setBusyLearnerId] = useState<number | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);

  const load = async () => {
    if (!token) return;

    setLoading(true);
    setError(null);

    try {
      const data = await apiRequest<AbsenceAlertsPayload>('/api/admin/absences/alerts', { token });
      setPayload(data);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const resend = async (learnerId: number) => {
    if (!token) return;

    setBusyLearnerId(learnerId);
    setFeedback(null);
    try {
      await apiRequest(`/api/admin/absences/alerts/${learnerId}/resend`, { method: 'POST', token });
      setFeedback("Email d'alerte renvoyé à pedagogie@edup-bs.com.");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Envoi impossible.');
    } finally {
      setBusyLearnerId(null);
    }
  };

  const sendDisciplinaryEmail = async (learnerId: number) => {
    if (!token) return;

    setBusyLearnerId(learnerId);
    setFeedback(null);
    try {
      const result = await apiRequest<{ message: string }>(`/api/admin/absences/alerts/${learnerId}/send-disciplinary-email`, {
        method: 'POST',
        token,
      });
      setFeedback(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : "Envoi impossible.");
    } finally {
      setBusyLearnerId(null);
    }
  };

  const reset = async (learnerId: number) => {
    if (!token) return;

    setBusyLearnerId(learnerId);
    setFeedback(null);
    try {
      await apiRequest(`/api/learners/${learnerId}/absence-counter/reset`, { method: 'POST', token });
      setFeedback('Compteur réinitialisé.');
      await load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Réinitialisation impossible.');
    } finally {
      setBusyLearnerId(null);
    }
  };

  return (
    <section className="flex flex-col gap-8">
      <div>
        <Breadcrumb items={[{ label: 'Absences' }, { label: 'Alertes' }]} />
        <h2 className="mt-1.5 font-display text-3xl font-extrabold tracking-tight">Alertes disciplinaires</h2>
      </div>

      <Card className="overflow-hidden border-0 bg-gradient-abs-alert text-white">
        <CardContent className="relative flex flex-wrap items-center justify-between gap-5 p-8">
          <div
            className="pointer-events-none absolute inset-0 opacity-20"
            style={{ backgroundImage: 'radial-gradient(circle at 85% 15%, rgba(255,255,255,.4), transparent 45%)' }}
          />
          <div className="relative">
            <span className="flex w-fit items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-abs-danger-100">
              <ShieldAlert size={14} /> Alerte disciplinaire
            </span>
            <h3 className="mt-2 font-display text-2xl font-extrabold leading-tight tracking-tight">
              3 absences injustifiées consécutives
            </h3>
            <p className="mt-1.5 max-w-xl text-sm text-white/80">
              Déclenchement automatique d&rsquo;un email à <strong>pedagogie@edup-bs.com</strong> pour
              engager la procédure disciplinaire (avertissement, courrier, suivi renforcé).
            </p>
          </div>
          <div className="relative flex gap-3">
            <div className="rounded-xl border border-white/20 bg-white/15 px-5 py-3 text-center backdrop-blur-sm">
              <p className="font-display text-3xl font-bold">{payload?.alerted.length ?? 0}</p>
              <p className="mt-0.5 text-xs text-white/80">alertes actives</p>
            </div>
            <div className="rounded-xl border border-white/20 bg-white/15 px-5 py-3 text-center backdrop-blur-sm">
              <p className="font-display text-3xl font-bold">{payload?.atRisk.length ?? 0}</p>
              <p className="mt-0.5 text-xs text-white/80">à surveiller</p>
            </div>
          </div>
        </CardContent>
      </Card>

      {loading ? <Card><CardContent className="p-5 text-sm text-muted-foreground">Chargement...</CardContent></Card> : null}
      {error ? <Card className="border-abs-danger-200 bg-abs-danger-50"><CardContent className="p-5 text-sm text-abs-danger-700">{error}</CardContent></Card> : null}
      {feedback ? <Card className="border-abs-success-200 bg-abs-success-50"><CardContent className="p-4 text-sm text-abs-success-700">{feedback}</CardContent></Card> : null}

      {payload ? (
        <>
          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Seuil atteint</p>
                <h3 className="font-display text-lg font-bold tracking-tight">Alertes actives</h3>
              </div>

              {payload.alerted.length === 0 ? (
                <div className="flex flex-col items-center gap-2 py-8 text-center text-muted-foreground">
                  <CheckCircle2 size={28} className="text-abs-success-500" />
                  <p className="text-sm">Aucune alerte active. Tous les seuils sont sous contrôle.</p>
                </div>
              ) : (
                <ul className="flex flex-col gap-3">
                  {payload.alerted.map((item) => (
                    <li key={item.learnerId} className="rounded-xl border border-abs-danger-200 bg-abs-danger-50/60 p-4">
                      <div className="flex flex-wrap items-center justify-between gap-4">
                        <button
                          onClick={() => navigate(`/learners/${item.learnerId}`)}
                          className="flex min-w-0 flex-1 items-center gap-3 text-left"
                        >
                          <AbsAvatar name={item.fullName} />
                          <div className="min-w-0">
                            <p className="truncate text-sm font-semibold">{item.fullName}</p>
                            <p className="truncate text-xs text-muted-foreground">
                              {item.group ?? 'Sans groupe'} {item.email ? `· ${item.email}` : ''}
                            </p>
                            <span className="mt-1.5 inline-flex w-fit items-center gap-1.5 rounded-full bg-abs-danger-100 px-2.5 py-1 text-xs font-medium text-abs-danger-800">
                              {item.consecutiveCount} abs. consécutives
                            </span>
                          </div>
                        </button>
                        <div className="flex shrink-0 flex-wrap gap-2">
                          <Button
                            size="sm"
                            className="bg-abs-danger-600 text-white hover:bg-abs-danger-700"
                            disabled={busyLearnerId === item.learnerId || !item.email}
                            title={!item.email ? "Cet apprenant n'a pas d'adresse email connue." : undefined}
                            onClick={() => void sendDisciplinaryEmail(item.learnerId)}
                          >
                            <Mail size={14} />
                            Email à l&rsquo;apprenant
                          </Button>
                          <Button variant="outline" size="sm" disabled={busyLearnerId === item.learnerId} onClick={() => void resend(item.learnerId)}>
                            <Send size={14} />
                            Renvoyer l&rsquo;email
                          </Button>
                          <Button variant="outline" size="sm" disabled={busyLearnerId === item.learnerId} onClick={() => void reset(item.learnerId)}>
                            <RotateCcw size={14} />
                            Réinitialiser
                          </Button>
                          <Button variant="ghost" size="sm" onClick={() => navigate(`/learners/${item.learnerId}`)}>
                            <ChevronRight size={16} />
                          </Button>
                        </div>
                      </div>

                      {item.recentAbsences.length > 0 ? (
                        <div className="mt-3 border-t border-abs-danger-200/70 pt-3">
                          <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-abs-danger-700">
                            <Mail size={12} /> Email envoyé à pedagogie@edup-bs.com
                          </p>
                          <ol className="flex flex-col gap-1.5">
                            {item.recentAbsences.map((absence, index) => (
                              <li key={absence.id} className="flex items-center gap-2 text-xs">
                                <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-abs-danger-100 font-bold text-abs-danger-700">
                                  {item.recentAbsences.length - index}
                                </span>
                                <span className="flex-1 truncate">{absence.sessionTitle}</span>
                                <span className="text-muted-foreground">{formatDateTime(absence.sessionStartAt)}</span>
                                <AbsStatusChip status={absence.status} />
                              </li>
                            ))}
                          </ol>
                        </div>
                      ) : null}
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Sous le seuil</p>
                <h3 className="font-display text-lg font-bold tracking-tight">À surveiller</h3>
              </div>

              {payload.atRisk.length === 0 ? (
                <div className="flex flex-col items-center gap-2 py-6 text-center text-muted-foreground">
                  <AlertTriangle size={22} />
                  <p className="text-sm">Aucun apprenant dans cette catégorie.</p>
                </div>
              ) : (
                <ul className="flex flex-col divide-y divide-border">
                  {payload.atRisk.map((item) => (
                    <li key={item.learnerId} className="py-3 first:pt-0 last:pb-0">
                      <button
                        onClick={() => navigate(`/learners/${item.learnerId}`)}
                        className="flex w-full items-center gap-3 rounded-lg px-2 py-1 text-left transition-colors hover:bg-abs-ink-50"
                      >
                        <AbsAvatar name={item.fullName} size="sm" />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">{item.fullName}</p>
                          <p className="truncate text-xs text-muted-foreground">{item.group ?? 'Sans groupe'}</p>
                        </div>
                        <div className="flex shrink-0 items-center gap-1.5">
                          <span className="rounded-full bg-abs-warning-100 px-2.5 py-1 text-xs font-medium text-abs-warning-800">
                            {item.consecutiveCount} / 3
                          </span>
                          <div className="flex gap-1">
                            {[1, 2, 3].map((step) => (
                              <div
                                key={step}
                                className={`h-1.5 w-6 rounded-full ${item.consecutiveCount >= step ? 'bg-abs-warning-500' : 'bg-abs-ink-100'}`}
                              />
                            ))}
                          </div>
                        </div>
                        <ChevronRight size={15} className="shrink-0 text-muted-foreground" />
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          <Card className="bg-abs-ink-50/60">
            <CardContent className="flex gap-3 p-5">
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-abs-ink-100 text-abs-ink-600">
                <ShieldAlert size={20} />
              </span>
              <div className="text-sm">
                <p className="font-semibold text-abs-ink-900">Règle métier</p>
                <p className="mt-1 leading-relaxed text-abs-ink-600">
                  Lorsqu&rsquo;un apprenant cumule <strong>3 absences injustifiées consécutives en masterclass</strong>
                  {' '}(y compris celles encore en attente de justificatif), un email d&rsquo;alerte est
                  automatiquement envoyé à <strong>pedagogie@edup-bs.com</strong> pour déclencher la procédure
                  disciplinaire. Un admin peut réinitialiser manuellement le compteur à tout moment.
                </p>
              </div>
            </CardContent>
          </Card>
        </>
      ) : null}
    </section>
  );
}
