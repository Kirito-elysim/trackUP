import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { TrainingDetail, TrainingSummary } from '../types/trackup';

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
      setSelectedTraining(null);
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

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Pilotage</p>
          <h2>Formations</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Consolidation des parcours, modules, sessions et engagement apprenant.</p>
          <span className="status-chip status-chip-soft">{trainings.length} formations</span>
        </div>
      </div>

      <div className="panel-card filters-panel">
        <label className="field field-inline">
          <span>Recherche</span>
          <input
            placeholder="Titre ou référence"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
        </label>

        <label className="field field-inline">
          <span>État</span>
          <select value={state} onChange={(event) => setState(event.target.value)}>
            <option value="">Tous</option>
            <option value="published">Publiée</option>
            <option value="draft">Brouillon</option>
            <option value="archived">Archivée</option>
          </select>
        </label>
      </div>

      {error ? <div className="panel-card error-panel">{error}</div> : null}

      <div className="learners-layout">
        <section className="panel-card learners-list-panel">
          <div className="panel-card-header">
            <div>
              <p className="section-kicker">Catalogue</p>
              <h3>Formations synchronisées</h3>
            </div>
          </div>

          {listLoading ? <p className="muted">Chargement des formations...</p> : null}

          <div className="learner-list">
            {trainings.map((training) => (
              <button
                className={`learner-list-item ${training.id === selectedTrainingId ? 'learner-list-item-active' : ''}`}
                key={training.id}
                onClick={() => setSelectedTrainingId(training.id)}
                type="button"
              >
                <div className="learner-list-main">
                  <strong>{training.title}</strong>
                  <p>{training.reference ?? training.type ?? 'Sans référence'}</p>
                </div>
                <div className="learner-list-meta">
                  <span>{training.learnersCount} apprenants</span>
                  <small>{formatPercentage(training.averageProgress)}</small>
                </div>
              </button>
            ))}

            {!listLoading && trainings.length === 0 ? <p className="muted">Aucune formation trouvée.</p> : null}
          </div>
        </section>

        <section className="learner-detail-column">
          <article className="panel-card detail-hero">
            <div className="detail-hero-main">
              <div>
                <p className="section-kicker">Fiche formation</p>
                <h3>{selectedSummary?.title ?? 'Sélectionne une formation'}</h3>
                <p className="muted">
                  {selectedSummary
                    ? `${selectedSummary.reference ?? selectedSummary.type ?? 'Référence non renseignée'} · Dernière synchro ${formatDateTime(selectedSummary.syncedAt)}`
                    : 'Aucun détail disponible.'}
                </p>
              </div>
              <span className="status-chip">{selectedSummary?.state ?? 'N/A'}</span>
            </div>

            {detailLoading ? <p className="muted">Chargement du détail...</p> : null}

            {selectedTraining ? (
              <div className="stats-grid stats-grid-compact">
                <article className="metric-card">
                  <p className="metric-label">Temps cumulé</p>
                  <strong className="metric-value">{formatDuration(selectedTraining.training.totalTime)}</strong>
                  <p className="metric-hint">Tous apprenants confondus</p>
                </article>
                <article className="metric-card">
                  <p className="metric-label">Progression moyenne</p>
                  <strong className="metric-value">{formatPercentage(selectedTraining.training.averageProgress)}</strong>
                  <p className="metric-hint">{selectedTraining.training.learnersCount} inscrits</p>
                </article>
                <article className="metric-card">
                  <p className="metric-label">Structure</p>
                  <strong className="metric-value">
                    {selectedTraining.modules.length} / {selectedTraining.sessions.length}
                  </strong>
                  <p className="metric-hint">Modules / sessions</p>
                </article>
              </div>
            ) : null}
          </article>

          {selectedTraining ? (
            <div className="content-grid content-grid-detail">
              <article className="panel-card">
                <div className="panel-card-header">
                  <div>
                    <p className="section-kicker">Modules</p>
                    <h3>Découpage pédagogique</h3>
                  </div>
                </div>

                <div className="stack">
                  {selectedTraining.modules.slice(0, 8).map((module) => (
                    <div className="list-row" key={module.id}>
                      <div>
                        <strong>{module.title}</strong>
                        <p className="muted">
                          {module.type ?? 'module'} · {module.stepCount} steps
                        </p>
                      </div>
                      <div className="list-row-meta">
                        <span>{formatDuration(module.eduDuration ?? module.duration ?? 0)}</span>
                        <small>Position {module.position ?? 'N/A'}</small>
                      </div>
                    </div>
                  ))}
                </div>
              </article>

              <article className="panel-card">
                <div className="panel-card-header">
                  <div>
                    <p className="section-kicker">Sessions</p>
                    <h3>Masterclass et classes</h3>
                  </div>
                </div>

                <div className="stack">
                  {selectedTraining.sessions.slice(0, 8).map((session) => (
                    <div className="list-row" key={session.id}>
                      <div>
                        <strong>{session.sessionType ?? 'Session'}</strong>
                        <p className="muted">{formatDateTime(session.startAt)}</p>
                      </div>
                      <div className="list-row-meta">
                        <span>{session.attendedCount}/{session.registrationCount}</span>
                        <small>{formatDuration(session.eduDuration ?? 0)}</small>
                      </div>
                    </div>
                  ))}
                </div>
              </article>
            </div>
          ) : null}

          {selectedTraining ? (
            <article className="panel-card">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Top apprenants</p>
                  <h3>Engagement sur la formation</h3>
                </div>
              </div>

              <div className="stack">
                {selectedTraining.topLearners.map((learner) => (
                  <div className="list-row" key={learner.id}>
                    <div>
                      <strong>{learner.fullName}</strong>
                      <p className="muted">{learner.email}</p>
                    </div>
                    <div className="list-row-meta">
                      <span>{formatDuration(learner.totalTime)}</span>
                      <small>{formatPercentage(learner.progress)}</small>
                    </div>
                  </div>
                ))}
              </div>
            </article>
          ) : null}
        </section>
      </div>
    </section>
  );
}
