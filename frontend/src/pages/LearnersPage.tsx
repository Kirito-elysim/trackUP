import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { LearnerDetail, LearnerSummary } from '../types/trackup';

export function LearnersPage() {
  const { token } = useAuth();
  const [query, setQuery] = useState('');
  const [state, setState] = useState('');
  const deferredQuery = useDeferredValue(query);
  const [learners, setLearners] = useState<LearnerSummary[]>([]);
  const [selectedLearnerId, setSelectedLearnerId] = useState<number | null>(null);
  const [selectedLearner, setSelectedLearner] = useState<LearnerDetail | null>(null);
  const [listLoading, setListLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const loadLearners = async () => {
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
        const payload = await apiRequest<LearnerSummary[]>(`/api/learners?${params.toString()}`, { token });

        if (cancelled) {
          return;
        }

        setLearners(payload);
        setSelectedLearnerId((current) => {
          if (payload.length === 0) {
            return null;
          }

          return current && payload.some((learner) => learner.id === current) ? current : payload[0].id;
        });
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
          setLearners([]);
          setSelectedLearnerId(null);
        }
      } finally {
        if (!cancelled) {
          setListLoading(false);
        }
      }
    };

    void loadLearners();

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, state, token]);

  useEffect(() => {
    if (!token || !selectedLearnerId) {
      setSelectedLearner(null);
      return;
    }

    let cancelled = false;

    const loadDetail = async () => {
      setDetailLoading(true);

      try {
        const payload = await apiRequest<LearnerDetail>(`/api/learners/${selectedLearnerId}`, { token });

        if (!cancelled) {
          setSelectedLearner(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Détail apprenant indisponible.');
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
  }, [selectedLearnerId, token]);

  const selectedSummary = useMemo(
    () => learners.find((learner) => learner.id === selectedLearnerId) ?? null,
    [learners, selectedLearnerId],
  );

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Pilotage</p>
          <h2>Apprenants</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Temps global, progression, sessions et historique d’activité à partir des données locales.</p>
          <span className="status-chip status-chip-soft">{learners.length} résultats</span>
        </div>
      </div>

      <div className="panel-card filters-panel">
        <label className="field field-inline">
          <span>Recherche</span>
          <input
            placeholder="Nom, prénom ou email"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
        </label>

        <label className="field field-inline">
          <span>État</span>
          <select value={state} onChange={(event) => setState(event.target.value)}>
            <option value="">Tous</option>
            <option value="active">Actif</option>
            <option value="inactive">Inactif</option>
            <option value="suspended">Suspendu</option>
          </select>
        </label>
      </div>

      {error ? <div className="panel-card error-panel">{error}</div> : null}

      <div className="learners-layout">
        <section className="panel-card learners-list-panel">
          <div className="panel-card-header">
            <div>
              <p className="section-kicker">Population</p>
              <h3>Liste consolidée</h3>
            </div>
          </div>

          {listLoading ? <p className="muted">Chargement des apprenants...</p> : null}

          <div className="learner-list">
            {learners.map((learner) => (
              <button
                className={`learner-list-item ${learner.id === selectedLearnerId ? 'learner-list-item-active' : ''}`}
                key={learner.id}
                onClick={() => setSelectedLearnerId(learner.id)}
                type="button"
              >
                <div className="learner-list-main">
                  <strong>{learner.fullName}</strong>
                  <p>{learner.email}</p>
                </div>
                <div className="learner-list-meta">
                  <span>{formatDuration(learner.totalTime)}</span>
                  <small>{formatPercentage(learner.averageProgress)}</small>
                </div>
              </button>
            ))}

            {!listLoading && learners.length === 0 ? <p className="muted">Aucun apprenant trouvé.</p> : null}
          </div>
        </section>

        <section className="learner-detail-column">
          <article className="panel-card detail-hero">
            <div className="detail-hero-main">
              <div>
                <p className="section-kicker">Fiche apprenant</p>
                <h3>{selectedSummary?.fullName ?? 'Sélectionne un apprenant'}</h3>
                <p className="muted">
                  {selectedSummary
                    ? `${selectedSummary.email} · Dernière activité ${formatDateTime(selectedSummary.lastActivityAt)}`
                    : 'Aucun détail disponible.'}
                </p>
              </div>
              <span className="status-chip">{selectedSummary?.state ?? 'N/A'}</span>
            </div>

            {detailLoading ? <p className="muted">Chargement du détail...</p> : null}

            {selectedLearner ? (
              <div className="stats-grid stats-grid-compact">
                <article className="metric-card">
                  <p className="metric-label">Temps global</p>
                  <strong className="metric-value">{formatDuration(selectedLearner.learner.totalTime)}</strong>
                  <p className="metric-hint">Toutes inscriptions formation consolidées</p>
                </article>
                <article className="metric-card">
                  <p className="metric-label">Progression moyenne</p>
                  <strong className="metric-value">{formatPercentage(selectedLearner.learner.averageProgress)}</strong>
                  <p className="metric-hint">{selectedLearner.learner.trainingCount} formations suivies</p>
                </article>
                <article className="metric-card">
                  <p className="metric-label">Sessions</p>
                  <strong className="metric-value">{selectedLearner.learner.sessionRegistrationCount}</strong>
                  <p className="metric-hint">{selectedLearner.learner.signedAttendanceCount} signatures confirmées</p>
                </article>
              </div>
            ) : null}
          </article>

          {selectedLearner ? (
            <div className="content-grid content-grid-detail">
              <article className="panel-card">
                <div className="panel-card-header">
                  <div>
                    <p className="section-kicker">Formations</p>
                    <h3>Inscriptions et temps passés</h3>
                  </div>
                </div>

                <div className="stack">
                  {selectedLearner.trainingRegistrations.slice(0, 8).map((registration) => (
                    <div className="list-row" key={registration.id}>
                      <div>
                        <strong>{registration.trainingTitle}</strong>
                        <p className="muted">
                          {registration.state} · Inscrit le {formatDateTime(registration.subscribedAt)}
                        </p>
                      </div>
                      <div className="list-row-meta">
                        <span>{formatDuration(registration.totalTime)}</span>
                        <small>{formatPercentage(registration.progress)}</small>
                      </div>
                    </div>
                  ))}
                </div>
              </article>

              <article className="panel-card">
                <div className="panel-card-header">
                  <div>
                    <p className="section-kicker">Masterclass</p>
                    <h3>Sessions et présence</h3>
                  </div>
                </div>

                <div className="stack">
                  {selectedLearner.sessionRegistrations.slice(0, 8).map((registration) => (
                    <div className="list-row" key={registration.id}>
                      <div>
                        <strong>{registration.trainingTitle ?? 'Session sans formation liée'}</strong>
                        <p className="muted">
                          {registration.sessionType ?? 'session'} · {formatDateTime(registration.startAt)}
                        </p>
                      </div>
                      <div className="list-row-meta">
                        <span>{registration.attended === null ? 'N/A' : registration.attended ? 'Présent' : 'Absent'}</span>
                        <small>{registration.signedCount} signature(s)</small>
                      </div>
                    </div>
                  ))}
                </div>
              </article>
            </div>
          ) : null}

          {selectedLearner ? (
            <article className="panel-card">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Activité récente</p>
                  <h3>Derniers steps remontés</h3>
                </div>
              </div>

              <div className="stack">
                {selectedLearner.recentActivities.slice(0, 10).map((activity) => (
                  <div className="list-row" key={activity.id}>
                    <div>
                      <strong>{activity.stepTitle}</strong>
                      <p className="muted">
                        {activity.trainingTitle} · {activity.moduleTitle} · {activity.stepType ?? 'step'}
                      </p>
                    </div>
                    <div className="list-row-meta">
                      <span>{formatDuration(activity.totalTime ?? activity.timeSpent ?? 0)}</span>
                      <small>{formatDateTime(activity.activityAt)}</small>
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
