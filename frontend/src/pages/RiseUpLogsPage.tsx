import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { ApiError, apiRequest } from '../lib/api';
import { formatDateTime, formatDuration } from '../lib/format';
import { Clock, User, BookOpen, Calendar, Filter, Download, RefreshCw, Users, TrendingUp, Activity } from 'lucide-react';
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
  const [groupExternalId, setGroupExternalId] = useState('');
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
    if (groupExternalId !== '') {
      params.set('groupExternalId', groupExternalId);
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
  }, [dateFrom, dateTo, learnerQuery, groupExternalId, learningPathId, page, pageSize, trainingExternalId]);

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
    setGroupExternalId('');
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
      if (groupExternalId !== '') {
        params.set('groupExternalId', groupExternalId);
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
    <div className="page-container">
      <div className="page-header-modern">
        <div>
          <div className="page-breadcrumb">
            <span className="breadcrumb-item">Conformité</span>
            <span className="breadcrumb-separator">›</span>
            <span className="breadcrumb-item active">Historique des activités</span>
          </div>
          <h1>Historique des activités</h1>
          <p className="page-description">Journal complet des sessions importées depuis les exports Rise Up</p>
        </div>
        <div className="page-header-actions">
          {payload && (
            <button 
              className="btn-export" 
              onClick={() => void exportCsv()} 
              type="button"
              disabled={exporting}
            >
              <Download size={18} />
              <span>{exporting ? 'Export en cours...' : 'Exporter CSV'}</span>
            </button>
          )}
        </div>
      </div>

      <div className="filters-section">
        <div className="filters-header">
          <div className="filters-title">
            <Filter size={20} />
            <h3>Filtres</h3>
          </div>
          <button
            className="btn-reset-filters"
            onClick={resetFilters}
            type="button"
          >
            <RefreshCw size={16} />
            <span>Réinitialiser</span>
          </button>
        </div>
        
        <div className="filters-grid">
          <div className="filter-group">
            <label className="filter-label">
              <User size={16} />
              <span>Recherche apprenant</span>
            </label>
            <input
              type="search"
              className="filter-input"
              placeholder="Nom ou email"
              value={learnerQuery}
              onChange={(event) => {
                setLearnerQuery(event.target.value);
                setPage(1);
              }}
            />
          </div>

          <div className="filter-group">
            <label className="filter-label">
              <Users size={16} />
              <span>Groupe / Classe</span>
            </label>
            <select
              className="filter-select"
              value={groupExternalId}
              onChange={(event) => {
                setGroupExternalId(event.target.value);
                setLearningPathId('');
                setTrainingExternalId('');
                setPage(1);
              }}
            >
              <option value="">Tous les groupes</option>
              {(payload?.filters.availableGroups ?? []).map((item) => (
                <option key={item.externalId} value={item.externalId}>
                  {item.name}
                </option>
              ))}
            </select>
          </div>

          <div className="filter-group">
            <label className="filter-label">
              <TrendingUp size={16} />
              <span>Parcours</span>
            </label>
            <select
              className="filter-select"
              value={learningPathId}
              onChange={(event) => {
                setLearningPathId(event.target.value);
                setTrainingExternalId('');
                setPage(1);
              }}
            >
              <option value="">{groupExternalId === '' ? 'Tous les parcours' : 'Parcours du groupe'}</option>
              {(payload?.filters.availableLearningPaths ?? []).map((item) => (
                <option key={item.id} value={item.id}>
                  {item.title}
                </option>
              ))}
            </select>
          </div>

          <div className="filter-group">
            <label className="filter-label">
              <BookOpen size={16} />
              <span>Formation</span>
            </label>
            <select
              className="filter-select"
              disabled={learningPathId === ''}
              value={trainingExternalId}
              onChange={(event) => {
                setTrainingExternalId(event.target.value);
                setPage(1);
              }}
            >
              <option value="">{learningPathId === '' ? 'Sélectionnez un parcours' : 'Toutes les formations'}</option>
              {(payload?.filters.availableTrainings ?? []).map((item) => (
                <option key={item.externalId} value={item.externalId}>
                  {item.title}
                </option>
              ))}
            </select>
          </div>

          <div className="filter-group">
            <label className="filter-label">
              <Calendar size={16} />
              <span>Date début</span>
            </label>
            <input
              type="date"
              className="filter-input"
              value={dateFrom}
              onChange={(event) => {
                setDateFrom(event.target.value);
                setPage(1);
              }}
            />
          </div>

          <div className="filter-group">
            <label className="filter-label">
              <Calendar size={16} />
              <span>Date fin</span>
            </label>
            <input
              type="date"
              className="filter-input"
              value={dateTo}
              onChange={(event) => {
                setDateTo(event.target.value);
                setPage(1);
              }}
            />
          </div>

          <div className="filter-group">
            <label className="filter-label">Résultats par page</label>
            <select
              className="filter-select"
              value={pageSize}
              onChange={(event) => {
                setPageSize(Number(event.target.value));
                setPage(1);
              }}
            >
              <option value={50}>50 résultats</option>
              <option value={100}>100 résultats</option>
              <option value={200}>200 résultats</option>
              <option value={500}>500 résultats</option>
            </select>
          </div>
        </div>
      </div>

      {loading && (
        <div className="loading-state" style={{ marginTop: '2rem' }}>
          <RefreshCw size={32} className="spin" />
          <p>Chargement de l'historique...</p>
        </div>
      )}
      
      {error && (
        <div className="error-state" style={{ marginTop: '2rem' }}>
          <p>{error}</p>
        </div>
      )}

      {payload && (
        <>

          {payload.groupContext ? (
            <article className="panel-card">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Groupe</p>
                  <h3>{payload.groupContext.name}</h3>
                  <p className="muted">{payload.groupContext.memberCount} membre(s)</p>
                </div>
              </div>
              {payload.groupContext.learningPaths.length > 0 ? (
                <div className="table-shell">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Parcours</th>
                        <th>Apprenants inscrits</th>
                      </tr>
                    </thead>
                    <tbody>
                      {payload.groupContext.learningPaths.map((item) => (
                        <tr key={item.id}>
                          <td>{item.title}</td>
                          <td>{item.learnerCount}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="muted">Aucun parcours trouvé pour ce groupe (via les inscriptions locales).</p>
              )}
            </article>
          ) : null}

          <div className="stats-overview">
            <div className="stat-card-modern">
              <div className="stat-icon" style={{ background: 'linear-gradient(135deg, #5B8DEE 0%, #0063F7 100%)' }}>
                <Activity size={24} color="white" />
              </div>
              <div className="stat-content">
                <p className="stat-label">Total de logs</p>
                <strong className="stat-value">{payload.metrics.logCount.toLocaleString()}</strong>
                <p className="stat-hint">Sessions importées</p>
              </div>
            </div>
            
            <div className="stat-card-modern">
              <div className="stat-icon" style={{ background: 'linear-gradient(135deg, #FF6B9D 0%, #C239B3 100%)' }}>
                <Users size={24} color="white" />
              </div>
              <div className="stat-content">
                <p className="stat-label">Apprenants</p>
                <strong className="stat-value">{payload.metrics.uniqueLearnersCount}</strong>
                <p className="stat-hint">Utilisateurs uniques</p>
              </div>
            </div>
            
            <div className="stat-card-modern">
              <div className="stat-icon" style={{ background: 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)' }}>
                <BookOpen size={24} color="white" />
              </div>
              <div className="stat-content">
                <p className="stat-label">Formations</p>
                <strong className="stat-value">{payload.metrics.uniqueTrainingsCount}</strong>
                <p className="stat-hint">Formations distinctes</p>
              </div>
            </div>
            
            <div className="stat-card-modern">
              <div className="stat-icon" style={{ background: 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)' }}>
                <Clock size={24} color="white" />
              </div>
              <div className="stat-content">
                <p className="stat-label">Temps total</p>
                <strong className="stat-value">{formatDuration(payload.metrics.totalDurationMinutes)}</strong>
                <p className="stat-hint">{formatDurationClock(payload.metrics.totalDurationSeconds)}</p>
              </div>
            </div>
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
      )}
    </div>
  );
}
