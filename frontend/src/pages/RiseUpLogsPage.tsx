import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { ApiError, apiRequest } from '../lib/api';
import { formatDateTime, formatDuration } from '../lib/format';
import type { RiseUpActivityLogsPayload } from '../types/trackup';

function formatDurationClock(totalSeconds: number): string {
  const seconds = Math.max(0, totalSeconds);
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainingSeconds = seconds % 60;

  return [hours, minutes, remainingSeconds].map((value) => String(value).padStart(2, '0')).join(':');
}

export function RiseUpLogsPage() {
  const { token } = useAuth();
  const [learnerQuery, setLearnerQuery] = useState('');
  const [learningPathId, setLearningPathId] = useState('');
  const [trainingExternalId, setTrainingExternalId] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(100);
  const [payload, setPayload] = useState<RiseUpActivityLogsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('pageSize', String(pageSize));

    if (learnerQuery !== '') {
      params.set('learnerQuery', learnerQuery);
    }
    if (learningPathId !== '') {
      params.set('learningPathId', learningPathId);
    }
    if (trainingExternalId !== '') {
      params.set('trainingExternalId', trainingExternalId);
    }
    if (dateFrom !== '') {
      params.set('dateFrom', dateFrom);
    }
    if (dateTo !== '') {
      params.set('dateTo', dateTo);
    }

    return params.toString();
  }, [dateFrom, dateTo, learnerQuery, learningPathId, page, pageSize, trainingExternalId]);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const data = await apiRequest<RiseUpActivityLogsPayload>(
          `/api/riseup-activity-logs${queryString !== '' ? `?${queryString}` : ''}`,
          { token },
        );

        if (!cancelled) {
          setPayload(data);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
          setPayload(null);
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [queryString, token]);

  const resetFilters = () => {
    setLearnerQuery('');
    setLearningPathId('');
    setTrainingExternalId('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  };

  const exportCsv = async () => {
    if (!payload || !token) {
      return;
    }

    setExporting(true);

    try {
      const params = new URLSearchParams();
      if (learnerQuery !== '') {
        params.set('learnerQuery', learnerQuery);
      }
      if (learningPathId !== '') {
        params.set('learningPathId', learningPathId);
      }
      if (trainingExternalId !== '') {
        params.set('trainingExternalId', trainingExternalId);
      }
      if (dateFrom !== '') {
        params.set('dateFrom', dateFrom);
      }
      if (dateTo !== '') {
        params.set('dateTo', dateTo);
      }

      const response = await fetch(
        `${import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8080'}/api/riseup-activity-logs/export${
          params.size > 0 ? `?${params.toString()}` : ''
        }`,
        {
          headers: {
            Authorization: `Bearer ${token}`,
          },
        },
      );

      if (!response.ok) {
        throw new ApiError('Export impossible.', response.status);
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `riseup-activity-logs-${new Date().toISOString().slice(0, 10)}.csv`;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Export impossible.');
    } finally {
      setExporting(false);
    }
  };

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Conformité</p>
          <h2>Logs exacts Rise Up</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Journal local des sessions importées depuis l’export d’activité Rise Up.</p>
          <span className="status-chip status-chip-soft">Source exacte</span>
        </div>
      </div>

      <div className="panel-card filters-panel analytics-filters">
        <label className="field field-inline">
          <span>Recherche apprenant</span>
          <input
            type="search"
            placeholder="Nom ou email"
            value={learnerQuery}
            onChange={(event) => {
              setLearnerQuery(event.target.value);
              setPage(1);
            }}
          />
        </label>

        <label className="field field-inline">
          <span>Parcours</span>
          <select
            value={learningPathId}
            onChange={(event) => {
              setLearningPathId(event.target.value);
              setTrainingExternalId('');
              setPage(1);
            }}
          >
            <option value="">Tous les parcours</option>
            {(payload?.filters.availableLearningPaths ?? []).map((item) => (
              <option key={item.id} value={item.id}>
                {item.title}
              </option>
            ))}
          </select>
        </label>

        <label className="field field-inline">
          <span>Formation liée au parcours</span>
          <select
            disabled={learningPathId === ''}
            value={trainingExternalId}
            onChange={(event) => {
              setTrainingExternalId(event.target.value);
              setPage(1);
            }}
          >
            <option value="">{learningPathId === '' ? 'Sélectionne d’abord un parcours' : 'Toutes les formations'}</option>
            {(payload?.filters.availableTrainings ?? []).map((item) => (
              <option key={item.externalId} value={item.externalId}>
                {item.title}
              </option>
            ))}
          </select>
        </label>

        <label className="field field-inline">
          <span>Du</span>
          <input
            type="date"
            value={dateFrom}
            onChange={(event) => {
              setDateFrom(event.target.value);
              setPage(1);
            }}
          />
        </label>

        <label className="field field-inline">
          <span>Au</span>
          <input
            type="date"
            value={dateTo}
            onChange={(event) => {
              setDateTo(event.target.value);
              setPage(1);
            }}
          />
        </label>

        <label className="field field-inline">
          <span>Par page</span>
          <select
            value={pageSize}
            onChange={(event) => {
              setPageSize(Number(event.target.value));
              setPage(1);
            }}
          >
            <option value={50}>50</option>
            <option value={100}>100</option>
            <option value={200}>200</option>
            <option value={500}>500</option>
          </select>
        </label>

        <button
          className="ghost-button analytics-clear-focus"
          onClick={resetFilters}
          type="button"
        >
          Réinitialiser
        </button>
      </div>

      {loading ? <div className="panel-card">Chargement des logs exacts...</div> : null}
      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {payload ? (
        <>
          <div className="hero-panel hero-panel-compact">
            <div className="hero-copy">
              <span className="status-chip">Journal importé</span>
              <h3>Sessions Rise Up vérifiables, sans reconstruction.</h3>
              <p>
                Cette vue repose uniquement sur les exports d’activité importés. Elle conserve les dates de connexion,
                les dates de déconnexion, la durée exacte et l’appareil quand Rise Up les fournit.
              </p>
            </div>
            <div className="hero-actions">
              <button className="ghost-button analytics-clear-focus" onClick={() => void exportCsv()} type="button">
                {exporting ? 'Export en cours...' : 'Export CSV complet'}
              </button>
            </div>
          </div>

          <div className="stats-grid">
            <article className="metric-card">
              <p className="metric-label">Logs</p>
              <strong className="metric-value">{payload.metrics.logCount}</strong>
              <p className="metric-hint">Lignes exactes importées</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Apprenants</p>
              <strong className="metric-value">{payload.metrics.uniqueLearnersCount}</strong>
              <p className="metric-hint">Apprenants distincts dans les logs</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Formations</p>
              <strong className="metric-value">{payload.metrics.uniqueTrainingsCount}</strong>
              <p className="metric-hint">Formations distinctes dans les logs</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Temps cumulé</p>
              <strong className="metric-value">{formatDuration(payload.metrics.totalDurationMinutes)}</strong>
              <p className="metric-hint">{formatDurationClock(payload.metrics.totalDurationSeconds)} exact</p>
            </article>
          </div>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Journal d’activité</p>
                <h3>
                  {payload.rows.length} log(s) filtré(s)
                </h3>
                <p className="muted">
                  {payload.pagination.totalRows} ligne(s) au total · page {payload.pagination.page} sur{' '}
                  {payload.pagination.totalPages}
                </p>
              </div>
              <p className="muted">Dernier import : {formatDateTime(payload.lastImportAt)}</p>
            </div>

            <div className="table-shell">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Connexion</th>
                    <th>Déconnexion</th>
                    <th>Durée</th>
                    <th>Appareil</th>
                    <th>Apprenant</th>
                    <th>Formation</th>
                    <th>Fichier source</th>
                  </tr>
                </thead>
                <tbody>
                  {payload.rows.map((row) => (
                    <tr key={row.id}>
                      <td>{formatDateTime(row.loginAt)}</td>
                      <td>{formatDateTime(row.logoutAt)}</td>
                      <td>
                        <strong>{formatDuration(row.durationMinutes)}</strong>
                        <span>{formatDurationClock(row.durationSeconds)}</span>
                      </td>
                      <td>{row.device ?? 'N/A'}</td>
                      <td>
                        <strong>{row.learnerFullName}</strong>
                        <span>{row.learnerEmail ?? 'Email indisponible'}</span>
                      </td>
                      <td>
                        <strong>{row.trainingTitle}</strong>
                        <span>ID Rise Up {row.trainingExternalId}</span>
                      </td>
                      <td>
                        <strong>{row.sourceFileName}</strong>
                        <span>{formatDateTime(row.sourceImportedAt)}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {payload.rows.length === 0 ? (
              <p className="muted">
                Aucun log importé pour ces filtres. Importe un export Rise Up avec la commande console pour alimenter
                cette vue.
              </p>
            ) : null}

            {payload.pagination.totalPages > 1 ? (
              <div className="panel-card-header">
                <p className="muted">
                  Affichage de {payload.rows.length} ligne(s) sur {payload.pagination.totalRows}
                </p>
                <div className="hero-actions">
                  <button
                    className="ghost-button analytics-clear-focus"
                    disabled={payload.pagination.page <= 1 || loading}
                    onClick={() => setPage((current) => Math.max(1, current - 1))}
                    type="button"
                  >
                    Précédent
                  </button>
                  <button
                    className="ghost-button analytics-clear-focus"
                    disabled={payload.pagination.page >= payload.pagination.totalPages || loading}
                    onClick={() =>
                      setPage((current) => Math.min(payload.pagination.totalPages, current + 1))
                    }
                    type="button"
                  >
                    Suivant
                  </button>
                </div>
              </div>
            ) : null}
          </article>
        </>
      ) : null}
    </section>
  );
}
