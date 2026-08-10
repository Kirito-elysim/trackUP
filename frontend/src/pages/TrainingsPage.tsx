import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { TrainingDetail, TrainingSummary } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Chip } from '@/components/ui/chip';
import { CountUp } from '@/components/ui/stat';
import { cn } from '@/lib/utils';

export function TrainingsPage() {
  const { token } = useAuth();
  const [query, setQuery] = useState('');
  const [state, setState] = useState('');
  const deferredQuery = useDeferredValue(query);
  const [trainings, setTrainings] = useState<TrainingSummary[]>([]);
  const [selectedTrainingId, setSelectedTrainingId] = useState<number | null>(null);
  const [selectedTraining, setSelectedTraining] = useState<TrainingDetail | null>(null);
  const [listLoading, setListLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const loadTrainings = async () => {
      setListLoading(true);
      setError(null);

      const params = new URLSearchParams({ limit: '60' });

      if (deferredQuery.trim() !== '') {
        params.set('q', deferredQuery.trim());
      }

      if (state !== '') {
        params.set('state', state);
      }

      try {
        const payload = await apiRequest<TrainingSummary[]>(`/api/trainings?${params.toString()}`, { token });

        if (cancelled) {
          return;
        }

        setTrainings(payload);
        setSelectedTrainingId((current) => {
          if (payload.length === 0) {
            return null;
          }

          return current && payload.some((training) => training.id === current) ? current : payload[0].id;
        });
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
          setTrainings([]);
          setSelectedTrainingId(null);
        }
      } finally {
        if (!cancelled) {
          setListLoading(false);
        }
      }
    };

    void loadTrainings();

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, state, token]);

  useEffect(() => {
    if (!token || !selectedTrainingId) {
      return;
    }

    let cancelled = false;

    const loadDetail = async () => {
      setDetailLoading(true);

      try {
        const payload = await apiRequest<TrainingDetail>(`/api/trainings/${selectedTrainingId}`, { token });

        if (!cancelled) {
          setSelectedTraining(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Détail formation indisponible.');
        }
      } finally {
        if (!cancelled) {
          setDetailLoading(false);
        }
      }
    };

    void loadDetail();

    return () => {
      cancelled = true;
    };
  }, [selectedTrainingId, token]);

  const selectedSummary = useMemo(
    () => trainings.find((training) => training.id === selectedTrainingId) ?? null,
    [trainings, selectedTrainingId],
  );

  // selectedTraining can briefly hold stale data from a previous selection while
  // selectedTrainingId has already moved on (or been cleared) — gate on both.
  const hasSelectedTrainingDetail = selectedTrainingId !== null && selectedTraining !== null;

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Pilotage</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Formations</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[38ch] text-sm text-muted-foreground">
            Consolidation des parcours, modules, sessions et engagement apprenant.
          </p>
          <Chip variant="neutral">{trainings.length} formations</Chip>
        </div>
      </div>

      <Card>
        <CardContent className="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
          <label className="flex flex-col gap-2">
            <span className="text-sm font-semibold">Recherche</span>
            <Input placeholder="Titre ou référence" value={query} onChange={(event) => setQuery(event.target.value)} />
          </label>

          <label className="flex flex-col gap-2">
            <span className="text-sm font-semibold">État</span>
            <Select value={state} onChange={(event) => setState(event.target.value)}>
              <option value="">Tous</option>
              <option value="published">Publiée</option>
              <option value="draft">Brouillon</option>
              <option value="archived">Archivée</option>
            </Select>
          </label>
        </CardContent>
      </Card>

      {error ? <Card className="border-destructive/30 bg-destructive/5"><CardContent className="p-5 text-sm text-destructive">{error}</CardContent></Card> : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[340px_1fr]">
        <Card className="h-fit">
          <CardContent className="flex flex-col gap-4 p-5">
            <div>
              <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Catalogue</p>
              <h3 className="font-display text-base font-bold tracking-tight">Formations synchronisées</h3>
            </div>

            {listLoading ? <p className="text-sm text-muted-foreground">Chargement des formations...</p> : null}

            <div className="flex max-h-[46rem] flex-col gap-2 overflow-y-auto">
              {trainings.map((training) => (
                <button
                  key={training.id}
                  onClick={() => setSelectedTrainingId(training.id)}
                  type="button"
                  className={cn(
                    'flex items-start justify-between gap-3 rounded-md border border-border p-3.5 text-left transition',
                    training.id === selectedTrainingId
                      ? 'border-primary/40 bg-primary/5'
                      : 'hover:border-primary/25 hover:bg-muted/40',
                  )}
                >
                  <div className="min-w-0">
                    <strong className="block truncate text-sm font-semibold">{training.title}</strong>
                    <p className="truncate text-xs text-muted-foreground">{training.reference ?? training.type ?? 'Sans référence'}</p>
                  </div>
                  <div className="flex shrink-0 flex-col items-end gap-0.5">
                    <span className="text-xs text-muted-foreground">{training.learnersCount} apprenants</span>
                    <span className="tabular text-xs font-semibold">{formatPercentage(training.averageProgress)}</span>
                  </div>
                </button>
              ))}

              {!listLoading && trainings.length === 0 ? <p className="text-sm text-muted-foreground">Aucune formation trouvée.</p> : null}
            </div>
          </CardContent>
        </Card>

        <div className="flex flex-col gap-6">
          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Fiche formation</p>
                  <h3 className="font-display text-xl font-bold tracking-tight">{selectedSummary?.title ?? 'Sélectionne une formation'}</h3>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {selectedSummary
                      ? `${selectedSummary.reference ?? selectedSummary.type ?? 'Référence non renseignée'} · Dernière synchro ${formatDateTime(selectedSummary.syncedAt)}`
                      : 'Aucun détail disponible.'}
                  </p>
                </div>
                <Chip variant="neutral">{selectedSummary?.state ?? 'N/A'}</Chip>
              </div>

              {detailLoading ? <p className="text-sm text-muted-foreground">Chargement du détail...</p> : null}

              {hasSelectedTrainingDetail ? (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <div className="flex flex-col gap-1.5 rounded-xl bg-muted/50 p-4">
                    <p className="text-[0.64rem] font-semibold uppercase tracking-wide text-muted-foreground">Temps cumulé</p>
                    <CountUp value={formatDuration(selectedTraining.training.totalTime)} className="text-lg" />
                    <p className="text-xs text-muted-foreground">Tous apprenants confondus</p>
                  </div>
                  <div className="flex flex-col gap-1.5 rounded-xl bg-muted/50 p-4">
                    <p className="text-[0.64rem] font-semibold uppercase tracking-wide text-muted-foreground">Progression moyenne</p>
                    <CountUp value={formatPercentage(selectedTraining.training.averageProgress)} className="text-lg" />
                    <p className="text-xs text-muted-foreground">{selectedTraining.training.learnersCount} inscrits</p>
                  </div>
                  <div className="flex flex-col gap-1.5 rounded-xl bg-muted/50 p-4">
                    <p className="text-[0.64rem] font-semibold uppercase tracking-wide text-muted-foreground">Structure</p>
                    <CountUp value={`${selectedTraining.modules.length} / ${selectedTraining.sessions.length}`} className="text-lg" />
                    <p className="text-xs text-muted-foreground">Modules / sessions</p>
                  </div>
                </div>
              ) : null}
            </CardContent>
          </Card>

          {hasSelectedTrainingDetail ? (
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <Card>
                <CardContent className="flex flex-col gap-4 p-6">
                  <div>
                    <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Modules</p>
                    <h3 className="font-display text-base font-bold tracking-tight">Découpage pédagogique</h3>
                  </div>
                  <div className="flex flex-col divide-y divide-border">
                    {selectedTraining.modules.slice(0, 8).map((module) => (
                      <div className="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0" key={module.id}>
                        <div>
                          <strong className="text-sm font-semibold">{module.title}</strong>
                          <p className="text-xs text-muted-foreground">
                            {module.type ?? 'module'} · {module.stepCount} steps
                          </p>
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-0.5 text-right">
                          <span className="tabular text-sm font-semibold">{formatDuration(module.eduDuration ?? module.duration ?? 0)}</span>
                          <span className="text-xs text-muted-foreground">Position {module.position ?? 'N/A'}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardContent className="flex flex-col gap-4 p-6">
                  <div>
                    <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Sessions</p>
                    <h3 className="font-display text-base font-bold tracking-tight">Masterclass et classes</h3>
                  </div>
                  <div className="flex flex-col divide-y divide-border">
                    {selectedTraining.sessions.slice(0, 8).map((session) => (
                      <div className="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0" key={session.id}>
                        <div>
                          <strong className="text-sm font-semibold">{session.sessionType ?? 'Session'}</strong>
                          <p className="text-xs text-muted-foreground">{formatDateTime(session.startAt)}</p>
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-0.5 text-right">
                          <span className="tabular text-sm font-semibold">
                            {session.attendedCount}/{session.registrationCount}
                          </span>
                          <span className="text-xs text-muted-foreground">{formatDuration(session.eduDuration ?? 0)}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            </div>
          ) : null}

          {hasSelectedTrainingDetail ? (
            <Card>
              <CardContent className="flex flex-col gap-4 p-6">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Top apprenants</p>
                  <h3 className="font-display text-base font-bold tracking-tight">Engagement sur la formation</h3>
                </div>
                <div className="flex flex-col divide-y divide-border">
                  {selectedTraining.topLearners.map((learner) => (
                    <div className="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0" key={learner.id}>
                      <div>
                        <strong className="text-sm font-semibold">{learner.fullName}</strong>
                        <p className="text-xs text-muted-foreground">{learner.email}</p>
                      </div>
                      <div className="flex shrink-0 flex-col items-end gap-0.5 text-right">
                        <span className="tabular text-sm font-semibold">{formatDuration(learner.totalTime)}</span>
                        <span className="text-xs text-muted-foreground">{formatPercentage(learner.progress)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          ) : null}
        </div>
      </div>
    </section>
  );
}
