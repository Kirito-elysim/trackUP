import { useDeferredValue, useEffect, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { LearningPathDetail, LearningPathSummary } from '../types/trackup';

export function LearningPathsPage() {
  const { token } = useAuth();
  const [query, setQuery] = useState('');
  const deferredQuery = useDeferredValue(query);
  const [learningPaths, setLearningPaths] = useState<LearningPathSummary[]>([]);
  const [selectedLearningPathId, setSelectedLearningPathId] = useState<number | null>(null);
  const [selectedLearningPath, setSelectedLearningPath] = useState<LearningPathDetail | null>(null);
  const [listLoading, setListLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const loadLearningPaths = async () => {
      setListLoading(true);
      setError(null);

      const params = new URLSearchParams({ limit: '100' });
      if (deferredQuery.trim() !== '') {
        params.set('q', deferredQuery.trim());
      }

      try {
        const payload = await apiRequest<LearningPathSummary[]>(`/api/learningpaths?${params.toString()}`, { token });

        if (cancelled) {
          return;
        }

        setLearningPaths(payload);
        setSelectedLearningPathId((current) => {
          if (payload.length === 0) {
            return null;
          }

          return current && payload.some((item) => item.id === current) ? current : payload[0].id;
        });
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement des parcours impossible.');
          setLearningPaths([]);
          setSelectedLearningPathId(null);
        }
      } finally {
        if (!cancelled) {
          setListLoading(false);
        }
      }
    };

    void loadLearningPaths();

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, token]);

  useEffect(() => {
    if (!token || !selectedLearningPathId) {
      setSelectedLearningPath(null);
      return;
    }

    let cancelled = false;

    const loadDetail = async () => {
      setDetailLoading(true);

      try {
        const payload = await apiRequest<LearningPathDetail>(`/api/learningpaths/${selectedLearningPathId}`, { token });

        if (!cancelled) {
          setSelectedLearningPath(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Détail parcours indisponible.');
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
  }, [selectedLearningPathId, token]);

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Pilotage</p>
          <h2>Parcours</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Parcours synchronisés depuis Rise Up, avec leurs formations liées et leurs inscriptions apprenants.</p>
          <span className="status-chip status-chip-soft">{learningPaths.length} parcours</span>
        </div>
      </div>

      <div className="panel-card filters-panel">
        <label className="field field-inline">
          <span>Recherche</span>
          <input placeholder="Titre ou référence du parcours" value={query} onChange={(event) => setQuery(event.target.value)} />
        </label>
        <div className="field field-inline">
          <span>Source</span>
          <div className="form-note">Rise Up en lecture seule</div>
        </div>
      </div>

      {error ? <div className="panel-card error-panel">{error}</div> : null}

      <div className="surface-stack">
        <article className="hero-panel hero-panel-compact">
          <div className="hero-copy">
            <span className="status-chip">Source LMS</span>
            <h3>Le parcours devient le niveau principal de lecture.</h3>
            <p>
              TrackUp récupère maintenant les parcours réels du tenant Rise Up, leurs formations associées et les
              inscriptions apprenants au niveau parcours.
            </p>
          </div>
        </article>

        <article className="panel-card">
          <div className="panel-card-header">
            <div>
              <p className="section-kicker">Catalogue</p>
              <h3>Parcours synchronisés</h3>
            </div>
          </div>

          {listLoading ? <p className="muted">Chargement des parcours...</p> : null}

          <div className="learner-list">
            {learningPaths.map((learningPath) => (
              <button
                className={`learner-list-item ${learningPath.id === selectedLearningPathId ? 'learner-list-item-active' : ''}`}
                key={learningPath.id}
                onClick={() => setSelectedLearningPathId(learningPath.id)}
                type="button"
              >
                <div className="learner-list-main">
                  <strong>{learningPath.title}</strong>
                  <p>{learningPath.reference ?? `#${learningPath.externalId}`}</p>
                </div>
                <div className="learner-list-meta">
                  <span>{learningPath.learnerCount} apprenants</span>
                  <small>{learningPath.trainingCount} formations</small>
                </div>
              </button>
            ))}

            {!listLoading && learningPaths.length === 0 ? <p className="muted">Aucun parcours synchronisé pour le moment.</p> : null}
          </div>
        </article>

        {selectedLearningPath ? (
          <article className="panel-card detail-hero">
            <div className="detail-hero-main">
              <div>
                <p className="section-kicker">Fiche parcours</p>
                <h3>{selectedLearningPath.learningPath.title}</h3>
                <p className="muted">
                  {selectedLearningPath.learningPath.reference ?? `ID Rise Up ${selectedLearningPath.learningPath.externalId}`} ·
                  Dernière synchro {formatDateTime(selectedLearningPath.learningPath.syncedAt)}
                </p>
              </div>
              <div className="learning-path-hero-side">
                <span className="status-chip">
                  {selectedLearningPath.learningPath.sequential ? 'Séquentiel' : 'Libre'}
                </span>
                {selectedLearningPath.learningPath.imageUrl ? (
                  <img
                    alt={selectedLearningPath.learningPath.title}
                    className="learning-path-cover"
                    src={selectedLearningPath.learningPath.imageUrl}
                  />
                ) : null}
              </div>
            </div>

            {detailLoading ? <p className="muted">Chargement du détail...</p> : null}

            <div className="stats-grid stats-grid-compact">
              <article className="metric-card">
                <p className="metric-label">Temps cumulé</p>
                <strong className="metric-value">{formatDuration(selectedLearningPath.learningPath.totalTime)}</strong>
                <p className="metric-hint">Agrégé depuis l'activité e-learning et les sessions</p>
              </article>
              <article className="metric-card">
                <p className="metric-label">Progression parcours</p>
                <strong className="metric-value">{formatPercentage(selectedLearningPath.learningPath.averageProgress)}</strong>
                <p className="metric-hint">{selectedLearningPath.learningPath.learnerCount} apprenants</p>
              </article>
              <article className="metric-card">
                <p className="metric-label">Structure</p>
                <strong className="metric-value">
                  {selectedLearningPath.learningPath.trainingCount}/{selectedLearningPath.learningPath.learnerCount}
                </strong>
                <p className="metric-hint">Formations / apprenants</p>
              </article>
            </div>

            <div className="content-grid content-grid-detail">
              <article className="panel-card">
                <div className="panel-card-header">
                  <div>
                    <p className="section-kicker">Formations du parcours</p>
                    <h3>{selectedLearningPath.trainings.length} formation(s)</h3>
                  </div>
                </div>
                <div className="table-shell">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Position</th>
                        <th>Formation</th>
                        <th>Temps</th>
                        <th>Progression</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedLearningPath.trainings.map((training) => (
                        <tr key={training.id}>
                          <td>{training.position ?? '-'}</td>
                          <td>
                            <strong>{training.title}</strong>
                            <span>{training.learnerCount} apprenants</span>
                          </td>
                          <td>{formatDuration(training.totalTime)}</td>
                          <td>{formatPercentage(training.averageProgress)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </article>

              <article className="panel-card">
                <div className="panel-card-header">
                  <div>
                    <p className="section-kicker">Apprenants inscrits</p>
                    <h3>{selectedLearningPath.learners.length} inscription(s)</h3>
                  </div>
                </div>
                <div className="table-shell">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Apprenant</th>
                        <th>Inscription</th>
                        <th>Temps</th>
                        <th>Progression</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedLearningPath.learners.map((learner) => (
                        <tr key={learner.id}>
                          <td>
                            <strong>{learner.fullName}</strong>
                            <span>{learner.email ?? 'Email indisponible'}</span>
                          </td>
                          <td>{learner.subscribedAt ? formatDateTime(learner.subscribedAt) : 'N/A'}</td>
                          <td>{formatDuration(learner.totalTime)}</td>
                          <td>{formatPercentage(learner.averageProgress)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </article>
            </div>
          </article>
        ) : (
          <article className="panel-card empty-state">
            <p>Sélectionne un parcours pour consulter ses formations liées et ses apprenants inscrits.</p>
          </article>
        )}
      </div>
    </section>
  );
}
