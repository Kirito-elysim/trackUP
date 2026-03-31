import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { AnalyticsPayload } from '../types/trackup';

const PERIOD_OPTIONS = [
  { value: 'day', label: 'Jour' },
  { value: '7d', label: '7 jours' },
  { value: '30d', label: '30 jours' },
  { value: 'month', label: 'Mois en cours' },
  { value: 'year', label: 'Année' },
  { value: 'custom', label: 'Personnalisée' },
];

export function AnalyticsPage() {
  const { token } = useAuth();
  const [period, setPeriod] = useState('30d');
  const [learningPathId, setLearningPathId] = useState('');
  const [learnerId, setLearnerId] = useState('');
  const [startAt, setStartAt] = useState('');
  const [endAt, setEndAt] = useState('');
  const [pathSort, setPathSort] = useState<'title' | 'totalTime' | 'timeProgressPercent' | 'averageProgress'>('totalTime');
  const [pathDirection, setPathDirection] = useState<'asc' | 'desc'>('desc');
  const [learnerSort, setLearnerSort] = useState<
    'fullName' | 'learningPathTitle' | 'totalTime' | 'trainingProgressPercent' | 'learningPathProgressPercent' | 'timeProgressPercent' | 'lastActivityAt'
  >('totalTime');
  const [learnerDirection, setLearnerDirection] = useState<'asc' | 'desc'>('desc');
  const [analytics, setAnalytics] = useState<AnalyticsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      const params = new URLSearchParams({ period });
      if (learningPathId !== '') {
        params.set('learningPathId', learningPathId);
      }
      if (learnerId !== '') {
        params.set('learnerId', learnerId);
      }
      if (period === 'custom') {
        if (startAt !== '') {
          params.set('startAt', startAt);
        }
        if (endAt !== '') {
          params.set('endAt', endAt);
        }
      }

      try {
        const payload = await apiRequest<AnalyticsPayload>(`/api/analytics?${params.toString()}`, { token });
        if (!cancelled) {
          setAnalytics(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement des analytics impossible.');
          setAnalytics(null);
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
  }, [endAt, learnerId, learningPathId, period, startAt, token]);

  const cards = analytics
    ? [
        {
          title: 'Temps tracé',
          value: formatDuration(analytics.metrics.totalTrackedTime),
          hint: `${analytics.metrics.activityCount} activités comptabilisées`,
        },
        {
          title: 'Temps e-learning',
          value: formatDuration(analytics.metrics.elearningTrackedTime),
          hint: 'Temps issu des steps et modules',
        },
        {
          title: 'Temps masterclass',
          value: formatDuration(analytics.metrics.masterclassTrackedTime),
          hint: 'Temps issu des sessions et masterclass',
        },
        {
          title: 'Apprenants actifs',
          value: analytics.metrics.activeLearnersCount.toString(),
          hint: `${analytics.metrics.learningPathsCount} parcours concernés`,
        },
        {
          title: 'Progression moyenne',
          value: formatPercentage(analytics.metrics.averageProgress),
          hint: 'Snapshot courant des inscriptions parcours',
        },
        {
          title: 'Dernière synchro',
          value: formatDateTime(analytics?.lastSyncAt),
          hint: 'Données locales consolidées',
        },
      ]
    : [];

  const comparisonCards = analytics
    ? [
        {
          title: 'Temps total',
          metric: analytics.comparison.totalTrackedTime,
          formatter: formatDuration,
        },
        {
          title: 'E-learning',
          metric: analytics.comparison.elearningTrackedTime,
          formatter: formatDuration,
        },
        {
          title: 'Masterclass',
          metric: analytics.comparison.masterclassTrackedTime,
          formatter: formatDuration,
        },
        {
          title: 'Apprenants actifs',
          metric: analytics.comparison.activeLearnersCount,
          formatter: (value: number) => String(Math.round(value)),
        },
      ]
    : [];

  useEffect(() => {
    setLearnerId('');
  }, [learningPathId]);

  const sortedLearningPaths = useMemo(() => {
    const rows = [...(analytics?.topLearningPaths ?? [])];
    rows.sort((left, right) => compareValues(left[pathSort], right[pathSort], pathDirection));
    return rows;
  }, [analytics?.topLearningPaths, pathDirection, pathSort]);

  const sortedLearnerRows = useMemo(() => {
    const rows = [...(analytics?.learnerPathRows ?? [])];
    rows.sort((left, right) => compareValues(left[learnerSort], right[learnerSort], learnerDirection));
    return rows;
  }, [analytics?.learnerPathRows, learnerDirection, learnerSort]);

  const selectedLearner = useMemo(
    () => sortedLearnerRows.find((row) => String(row.learnerId) === learnerId && String(row.learningPathId) === learningPathId) ?? null,
    [learnerId, learningPathId, sortedLearnerRows],
  );

  const handlePathSort = (key: typeof pathSort) => {
    if (pathSort === key) {
      setPathDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
      return;
    }

    setPathSort(key);
    setPathDirection(key === 'title' ? 'asc' : 'desc');
  };

  const handleLearnerSort = (key: typeof learnerSort) => {
    if (learnerSort === key) {
      setLearnerDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
      return;
    }

    setLearnerSort(key);
    setLearnerDirection(key === 'fullName' || key === 'learningPathTitle' ? 'asc' : 'desc');
  };

  const exportCsv = () => {
    if (!analytics) {
      return;
    }

    const headers = [
      'Apprenant',
      'Email',
      'Parcours',
      'Temps periode',
      'Temps e-learning',
      'Temps masterclass',
      '% avancement formation',
      '% avancement parcours',
      '% temps parcours',
      'Derniere activite',
      'Derniere connexion',
    ];

    const rows = sortedLearnerRows.map((row) => [
      row.fullName,
      row.email ?? '',
      row.learningPathTitle,
      formatDuration(row.totalTime),
      formatDuration(row.elearningTime),
      formatDuration(row.masterclassTime),
      formatPercentage(row.trainingProgressPercent),
      formatPercentage(row.learningPathProgressPercent),
      formatPercentage(row.timeProgressPercent),
      formatDateTime(row.lastActivityAt),
      formatDateTime(row.lastLoginAt),
    ]);

    const csv = [headers, ...rows]
      .map((line) => line.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(';'))
      .join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `trackup-analytics-${analytics.filters.period}.csv`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  const focusLearnerRow = (nextLearningPathId: number, nextLearnerId: number) => {
    if (learningPathId === String(nextLearningPathId) && learnerId === String(nextLearnerId)) {
      setLearnerId('');
      return;
    }

    setLearningPathId(String(nextLearningPathId));
    setLearnerId(String(nextLearnerId));
  };

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Pilotage</p>
          <h2>Analytics</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Lecture rapide de l’activité, du temps tracé et de la progression par période.</p>
          <div className="analytics-header-actions">
            <span className="status-chip status-chip-soft">
              {analytics ? `${analytics.filters.period} · ${formatDateTime(analytics.filters.endAt)}` : 'Chargement'}
            </span>
            <button className="ghost-button" onClick={exportCsv} type="button">
              Export CSV
            </button>
          </div>
        </div>
      </div>

      <div className="panel-card filters-panel analytics-filters">
        <label className="field field-inline">
          <span>Période</span>
          <select value={period} onChange={(event) => setPeriod(event.target.value)}>
            {PERIOD_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <label className="field field-inline">
          <span>Parcours</span>
          <select value={learningPathId} onChange={(event) => setLearningPathId(event.target.value)}>
            <option value="">Tous les parcours</option>
            {(analytics?.filters.availableLearningPaths ?? []).map((item) => (
              <option key={item.id} value={item.id}>
                {item.title}
              </option>
            ))}
          </select>
        </label>

        <label className="field field-inline">
          <span>Apprenant</span>
          <select value={learnerId} onChange={(event) => setLearnerId(event.target.value)}>
            <option value="">Tous les apprenants</option>
            {(analytics?.filters.availableLearners ?? []).map((item) => (
              <option key={item.id} value={item.id}>
                {item.fullName}
              </option>
            ))}
          </select>
        </label>

        {period === 'custom' ? (
          <>
            <label className="field field-inline">
              <span>Début</span>
              <input type="date" value={startAt} onChange={(event) => setStartAt(event.target.value)} />
            </label>
            <label className="field field-inline">
              <span>Fin</span>
              <input type="date" value={endAt} onChange={(event) => setEndAt(event.target.value)} />
            </label>
          </>
        ) : null}
      </div>

      {loading ? <div className="panel-card">Chargement des analytics...</div> : null}
      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {analytics ? (
        <>
          <div className="hero-panel">
            <div className="hero-copy">
              <span className="status-chip">Lecture tabulaire</span>
              <h3>Un suivi simple à lire, centré sur le temps et l’avancement.</h3>
              <p>
                Cette vue privilégie les tableaux pour aller vite : temps sur la période, progression formation et
                pourcentage d’avancement du temps dans le parcours.
              </p>
            </div>
          </div>

          <div className="stats-grid">
            {cards.map((card) => (
              <article className="metric-card" key={card.title}>
                <p className="metric-label">{card.title}</p>
                <strong className="metric-value">{card.value}</strong>
                <p className="metric-hint">{card.hint}</p>
              </article>
            ))}
          </div>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Comparaison</p>
                <h3>Écart avec la période précédente</h3>
              </div>
              <span className="status-chip status-chip-contrast">
                {formatDateTime(analytics.filters.previousStartAt)} → {formatDateTime(analytics.filters.previousEndAt)}
              </span>
            </div>

            <div className="stats-grid stats-grid-compact">
              {comparisonCards.map((card) => (
                <article className="metric-card" key={card.title}>
                  <p className="metric-label">{card.title}</p>
                  <strong className="metric-value">{card.formatter(card.metric.current)}</strong>
                  <p className="metric-hint">
                    Avant : {card.formatter(card.metric.previous)} · {formatSignedDelta(card.metric.delta, card.formatter)} (
                    {formatSignedPercent(card.metric.percentDelta)})
                  </p>
                </article>
              ))}
            </div>
          </article>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Période</p>
                <h3>Temps tracé par tranche</h3>
              </div>
            </div>

            <div className="table-shell">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Période</th>
                    <th>Temps total</th>
                    <th>E-learning</th>
                    <th>Masterclass</th>
                    <th>Activités</th>
                    <th>Apprenants actifs</th>
                  </tr>
                </thead>
                <tbody>
                  {analytics.timeSeries.map((item) => (
                    <tr key={item.bucketKey}>
                      <td>{item.label}</td>
                      <td>{formatDuration(item.totalTime)}</td>
                      <td>{formatDuration(item.elearningTime)}</td>
                      <td>{formatDuration(item.masterclassTime)}</td>
                      <td>{item.activityCount}</td>
                      <td>{item.activeLearnersCount}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </article>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Parcours</p>
                <h3>Synthèse par parcours</h3>
              </div>
            </div>

            <div className="table-shell">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>
                      <button className="table-sort-button" onClick={() => handlePathSort('title')} type="button">Parcours</button>
                    </th>
                    <th>Apprenants</th>
                    <th>
                      <button className="table-sort-button" onClick={() => handlePathSort('totalTime')} type="button">Temps période</button>
                    </th>
                    <th>E-learning</th>
                    <th>Masterclass</th>
                    <th>Durée cible</th>
                    <th>
                      <button className="table-sort-button" onClick={() => handlePathSort('timeProgressPercent')} type="button">% temps parcours</button>
                    </th>
                    <th>
                      <button className="table-sort-button" onClick={() => handlePathSort('averageProgress')} type="button">% avancement formation</button>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {sortedLearningPaths.map((item) => (
                    <tr key={item.id}>
                      <td>
                        <strong>{item.title}</strong>
                      </td>
                      <td>{item.learnerCount}</td>
                      <td>{formatDuration(item.totalTime)}</td>
                      <td>{formatDuration(item.elearningTime)}</td>
                      <td>{formatDuration(item.masterclassTime)}</td>
                      <td>{formatDuration(item.targetDuration)}</td>
                      <td>{formatPercentage(item.timeProgressPercent)}</td>
                      <td>{formatPercentage(item.averageProgress)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </article>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Formations</p>
                <h3>{selectedLearner ? `Détail des formations de ${selectedLearner.fullName}` : 'Détail par formation'}</h3>
              </div>
              <span className="status-chip status-chip-contrast">
                {analytics.trainingRows.length} formation(s) dans le périmètre
              </span>
            </div>

            {selectedLearner ? (
              <div className="analytics-focus-card">
                <div>
                  <p className="section-kicker">Focus apprenant</p>
                  <strong>{selectedLearner.fullName}</strong>
                  <p className="muted">
                    {selectedLearner.learningPathTitle} · {formatDuration(selectedLearner.totalTime)} sur la période
                  </p>
                </div>
                <button className="ghost-button analytics-clear-focus" onClick={() => setLearnerId('')} type="button">
                  Retirer le focus
                </button>
              </div>
            ) : (
              <p className="muted analytics-helper-text">
                Clique une ligne du tableau apprenant pour focaliser le détail formation sur un apprenant précis.
              </p>
            )}

            <div className="table-shell">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Formation</th>
                    <th>Apprenants</th>
                    <th>Temps total</th>
                    <th>E-learning</th>
                    <th>Masterclass</th>
                    <th>Durée cible</th>
                    <th>% temps formation</th>
                    <th>% avancement moyen</th>
                  </tr>
                </thead>
                <tbody>
                  {analytics.trainingRows.map((row) => (
                    <tr key={row.trainingId}>
                      <td>
                        <strong>{row.title}</strong>
                      </td>
                      <td>{row.learnerCount}</td>
                      <td>{formatDuration(row.totalTime)}</td>
                      <td>{formatDuration(row.elearningTime)}</td>
                      <td>{formatDuration(row.masterclassTime)}</td>
                      <td>{formatDuration(row.targetDuration)}</td>
                      <td>{formatPercentage(row.timeProgressPercent)}</td>
                      <td>{formatPercentage(row.averageProgress)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </article>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Détail apprenants</p>
                <h3>Temps et avancement par parcours</h3>
              </div>
            </div>

            <div className="table-shell">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>
                      <button className="table-sort-button" onClick={() => handleLearnerSort('fullName')} type="button">Apprenant</button>
                    </th>
                    <th>
                      <button className="table-sort-button" onClick={() => handleLearnerSort('learningPathTitle')} type="button">Parcours</button>
                    </th>
                    <th>
                      <button className="table-sort-button" onClick={() => handleLearnerSort('totalTime')} type="button">Temps période</button>
                    </th>
                    <th>E-learning</th>
                    <th>Masterclass</th>
                    <th>
                      <button className="table-sort-button" onClick={() => handleLearnerSort('trainingProgressPercent')} type="button">% avancement formation</button>
                    </th>
                    <th>
                      <button className="table-sort-button" onClick={() => handleLearnerSort('learningPathProgressPercent')} type="button">% avancement parcours</button>
                    </th>
                    <th>
                      <button className="table-sort-button" onClick={() => handleLearnerSort('timeProgressPercent')} type="button">% temps parcours</button>
                    </th>
                    <th>
                      <button className="table-sort-button" onClick={() => handleLearnerSort('lastActivityAt')} type="button">Dernière activité</button>
                    </th>
                    <th>Dernière connexion</th>
                  </tr>
                </thead>
                <tbody>
                  {sortedLearnerRows.map((row) => (
                    <tr
                      className={
                        learningPathId === String(row.learningPathId) && learnerId === String(row.learnerId)
                          ? 'analytics-row-active'
                          : 'analytics-row-clickable'
                      }
                      key={`${row.learnerId}-${row.learningPathId}`}
                      onClick={() => focusLearnerRow(row.learningPathId, row.learnerId)}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                          event.preventDefault();
                          focusLearnerRow(row.learningPathId, row.learnerId);
                        }
                      }}
                      role="button"
                      tabIndex={0}
                    >
                      <td>
                        <strong>{row.fullName}</strong>
                        <span>{row.email ?? 'Email indisponible'}</span>
                      </td>
                      <td>{row.learningPathTitle}</td>
                      <td>{formatDuration(row.totalTime)}</td>
                      <td>{formatDuration(row.elearningTime)}</td>
                      <td>{formatDuration(row.masterclassTime)}</td>
                      <td>{formatPercentage(row.trainingProgressPercent)}</td>
                      <td>{formatPercentage(row.learningPathProgressPercent)}</td>
                      <td>{formatPercentage(row.timeProgressPercent)}</td>
                      <td>{formatDateTime(row.lastActivityAt)}</td>
                      <td>{formatDateTime(row.lastLoginAt)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </article>
        </>
      ) : null}
    </section>
  );
}

function compareValues(left: string | number | null, right: string | number | null, direction: 'asc' | 'desc') {
  const leftValue = left ?? '';
  const rightValue = right ?? '';
  const multiplier = direction === 'asc' ? 1 : -1;

  if (typeof leftValue === 'number' && typeof rightValue === 'number') {
    return (leftValue - rightValue) * multiplier;
  }

  return String(leftValue).localeCompare(String(rightValue), 'fr', { numeric: true }) * multiplier;
}

function formatSignedPercent(value: number) {
  const rounded = value.toFixed(2);
  return value > 0 ? `+${rounded}%` : `${rounded}%`;
}

function formatSignedDelta(value: number, formatter: (value: number) => string) {
  if (value === 0) {
    return formatter(0);
  }

  const formatted = formatter(Math.abs(value));

  return value > 0 ? `+${formatted}` : `-${formatted}`;
}
