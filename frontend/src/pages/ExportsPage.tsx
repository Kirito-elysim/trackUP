import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { ExportsPayload } from '../types/trackup';

export function ExportsPage() {
  const { token } = useAuth();
  const [learnerId, setLearnerId] = useState('');
  const [payload, setPayload] = useState<ExportsPayload | null>(null);
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

      const params = new URLSearchParams();
      if (learnerId !== '') {
        params.set('learnerId', learnerId);
      }

      try {
        const data = await apiRequest<ExportsPayload>(`/api/exports${params.size > 0 ? `?${params.toString()}` : ''}`, { token });

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
  }, [learnerId, token]);

  const exportCsv = () => {
    if (!selectedLearner) {
      return;
    }

    const headers = [
      'Date',
      'Type',
      'Libelle',
      'Parcours',
      'Formation',
      'Module',
      'Step',
      'Duree',
      'Statut',
      'Signature',
      'Details',
    ];

    const rows = selectedLearner.logs.map((log) => [
      formatDateTime(log.occurredAt),
      log.sourceType,
      log.sourceLabel ?? '',
      log.learningPathTitle ?? '',
      log.trainingTitle ?? '',
      log.moduleTitle ?? '',
      log.stepTitle ?? '',
      formatDuration(log.duration),
      log.status ?? '',
      log.signed === null ? '' : log.signed ? 'Oui' : 'Non',
      log.details ?? '',
    ]);

    const csv = [headers, ...rows]
      .map((line) => line.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(';'))
      .join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `trackup-export-${selectedLearner.learner.fullName.replaceAll(' ', '-').toLowerCase()}.csv`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  const selectedLearner = payload?.selectedLearner ?? null;
  const learner = selectedLearner?.learner ?? null;
  const logCounts = useMemo(() => {
    if (!selectedLearner) {
      return { pathway: 0, platform: 0, module: 0, masterclass: 0 };
    }

    return selectedLearner.logs.reduce(
      (accumulator, log) => {
        if (log.sourceType === 'pathway') accumulator.pathway += 1;
        if (log.sourceType === 'platform') accumulator.platform += 1;
        if (log.sourceType === 'module') accumulator.module += 1;
        if (log.sourceType === 'masterclass') accumulator.masterclass += 1;

        return accumulator;
      },
      { pathway: 0, platform: 0, module: 0, masterclass: 0 },
    );
  }, [selectedLearner]);

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Conformité</p>
          <h2>Exports</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Sélectionne un apprenant et reconstruis un journal exploitable de tout son parcours.</p>
          <span className="status-chip status-chip-soft">Export apprenant</span>
        </div>
      </div>

      <div className="panel-card filters-panel analytics-filters">
        <label className="field field-inline">
          <span>Apprenant</span>
          <select value={learnerId} onChange={(event) => setLearnerId(event.target.value)}>
            <option value="">Choisir un apprenant</option>
            {(payload?.filters.availableLearners ?? []).map((item) => (
              <option key={item.id} value={item.id}>
                {item.fullName} · {item.email}
              </option>
            ))}
          </select>
        </label>
      </div>

      {loading ? <div className="panel-card">Chargement des données d’export...</div> : null}
      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {payload ? (
        <>
          <div className="hero-panel hero-panel-compact">
            <div className="hero-copy">
              <span className="status-chip">Dossier apprenant</span>
              <h3>Un export clair, chronologique et vérifiable.</h3>
              <p>
                Le dossier regroupe les parcours, les formations, les activités modules et les masterclass de
                l’apprenant sélectionné. Les temps `plateforme`, `module` et `masterclass` sont affichés séparément
                pour éviter les ambiguïtés.
              </p>
            </div>
          </div>

          <div className="stats-grid">
            <article className="metric-card">
              <p className="metric-label">Apprenants avec temps</p>
              <strong className="metric-value">{payload.metrics.learnersReadyCount}</strong>
              <p className="metric-hint">Base exportable actuelle</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Parcours suivis</p>
              <strong className="metric-value">{payload.metrics.learningPathsCount}</strong>
              <p className="metric-hint">Parcours détectés localement</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Signatures remontées</p>
              <strong className="metric-value">{payload.metrics.signedRegistrationsCount}</strong>
              <p className="metric-hint">Preuves masterclass</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Alertes conformité</p>
              <strong className="metric-value">{payload.metrics.sessionsWithoutSignatureCount}</strong>
              <p className="metric-hint">Présences sans signature</p>
            </article>
          </div>

          {learner ? (
            <>
              <article className="panel-card detail-hero">
                <div className="detail-hero-main">
                  <div>
                    <p className="section-kicker">Apprenant sélectionné</p>
                    <h3>{learner.fullName}</h3>
                    <p className="muted">
                      {learner.email} · dernière connexion connue {formatDateTime(learner.lastLoginAt)}
                    </p>
                  </div>
                  <button className="ghost-button analytics-clear-focus" onClick={exportCsv} type="button">
                    Export CSV
                  </button>
                </div>

                <div className="stats-grid stats-grid-compact">
                  <article className="metric-card">
                    <p className="metric-label">Temps plateforme</p>
                    <strong className="metric-value">{formatDuration(learner.platformTime)}</strong>
                    <p className="metric-hint">Total consolidé par inscriptions formation</p>
                  </article>
                  <article className="metric-card">
                    <p className="metric-label">Temps module</p>
                    <strong className="metric-value">{formatDuration(learner.moduleTime)}</strong>
                    <p className="metric-hint">Activités steps réellement tracées</p>
                  </article>
                  <article className="metric-card">
                    <p className="metric-label">Temps masterclass</p>
                    <strong className="metric-value">{formatDuration(learner.masterclassTime)}</strong>
                    <p className="metric-hint">Sessions et visios remontées</p>
                  </article>
                  <article className="metric-card">
                    <p className="metric-label">Parcours / formations</p>
                    <strong className="metric-value">
                      {learner.learningPathCount} / {learner.trainingCount}
                    </strong>
                    <p className="metric-hint">
                      {learner.signedAttendanceCount} signature(s) · {learner.unsignedAttendanceCount} alerte(s)
                    </p>
                  </article>
                </div>
              </article>

              <div className="content-grid">
                <article className="panel-card panel-card-emphasis">
                  <div className="panel-card-header">
                    <div>
                      <p className="section-kicker">Parcours</p>
                      <h3>Synthèse du parcours</h3>
                    </div>
                  </div>

                  <div className="table-shell">
                    <table className="data-table">
                      <thead>
                        <tr>
                          <th>Parcours</th>
                          <th>Inscription</th>
                          <th>Formations</th>
                          <th>Temps plateforme</th>
                          <th>Temps module</th>
                          <th>Temps masterclass</th>
                          <th>Avancement</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(selectedLearner?.learningPaths ?? []).map((path) => (
                          <tr key={path.learningPathId}>
                            <td>
                              <strong>{path.title}</strong>
                              <span>{path.reference ?? 'Référence non renseignée'}</span>
                            </td>
                            <td>{formatDateTime(path.subscribedAt)}</td>
                            <td>{path.trainingCount}</td>
                            <td>{formatDuration(path.platformTime)}</td>
                            <td>{formatDuration(path.moduleTime)}</td>
                            <td>{formatDuration(path.masterclassTime)}</td>
                            <td>{formatPercentage(path.progress)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </article>

                <article className="panel-card">
                  <div className="panel-card-header">
                    <div>
                      <p className="section-kicker">Volumes</p>
                      <h3>Répartition des traces</h3>
                    </div>
                  </div>

                  <div className="stack">
                    <div className="list-row">
                      <div>
                        <strong>Inscriptions parcours</strong>
                        <p className="muted">Entrées de structure</p>
                      </div>
                      <div className="list-row-meta">
                        <span>{logCounts.pathway}</span>
                      </div>
                    </div>
                    <div className="list-row">
                      <div>
                        <strong>Traces plateforme</strong>
                        <p className="muted">Inscriptions formation consolidées</p>
                      </div>
                      <div className="list-row-meta">
                        <span>{logCounts.platform}</span>
                      </div>
                    </div>
                    <div className="list-row">
                      <div>
                        <strong>Activités module</strong>
                        <p className="muted">Steps et modules e-learning</p>
                      </div>
                      <div className="list-row-meta">
                        <span>{logCounts.module}</span>
                      </div>
                    </div>
                    <div className="list-row">
                      <div>
                        <strong>Masterclass</strong>
                        <p className="muted">Présences et sessions remontées</p>
                      </div>
                      <div className="list-row-meta">
                        <span>{logCounts.masterclass}</span>
                      </div>
                    </div>
                  </div>
                </article>
              </div>

              <article className="panel-card">
                <div className="panel-card-header">
                  <div>
                    <p className="section-kicker">Formations</p>
                    <h3>Détail par formation</h3>
                  </div>
                </div>

                <div className="table-shell">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Formation</th>
                        <th>Parcours liés</th>
                        <th>Inscription</th>
                        <th>Temps plateforme</th>
                        <th>Temps module</th>
                        <th>Temps masterclass</th>
                        <th>Avancement</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(selectedLearner?.trainings ?? []).map((training) => (
                        <tr key={training.trainingId}>
                          <td>
                            <strong>{training.title}</strong>
                            <span>{training.state ?? 'État non renseigné'}</span>
                          </td>
                          <td>{training.learningPathTitles.join(', ') || 'Aucun parcours lié'}</td>
                          <td>{formatDateTime(training.subscribedAt)}</td>
                          <td>{formatDuration(training.platformTime)}</td>
                          <td>{formatDuration(training.moduleTime)}</td>
                          <td>{formatDuration(training.masterclassTime)}</td>
                          <td>{formatPercentage(training.progress)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </article>

              <article className="panel-card">
                <div className="panel-card-header">
                  <div>
                    <p className="section-kicker">Journal</p>
                    <h3>Logs chronologiques du parcours</h3>
                  </div>
                </div>

                <div className="table-shell">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Libellé</th>
                        <th>Parcours</th>
                        <th>Formation</th>
                        <th>Module / Step</th>
                        <th>Temps</th>
                        <th>Statut</th>
                        <th>Signature</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(selectedLearner?.logs ?? []).map((log, index) => (
                        <tr key={`${log.sourceType}-${log.occurredAt}-${index}`}>
                          <td>{formatDateTime(log.occurredAt)}</td>
                          <td>{renderSourceType(log.sourceType)}</td>
                          <td>
                            <strong>{log.sourceLabel ?? 'Activité'}</strong>
                            <span>{log.details ?? 'Aucun détail'}</span>
                          </td>
                          <td>{log.learningPathTitle ?? 'Non rattaché'}</td>
                          <td>{log.trainingTitle ?? 'Non rattachée'}</td>
                          <td>{[log.moduleTitle, log.stepTitle].filter(Boolean).join(' · ') || 'n/a'}</td>
                          <td>{formatDuration(log.duration)}</td>
                          <td>{log.status ?? 'Non renseigné'}</td>
                          <td>{log.signed === null ? '-' : log.signed ? 'Oui' : 'Non'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </article>
            </>
          ) : (
            <article className="panel-card">
              <p className="muted">
                Choisis un apprenant pour charger son dossier exportable : parcours, formations, temps consolidés et
                journal de traces.
              </p>
            </article>
          )}
        </>
      ) : null}
    </section>
  );
}

function renderSourceType(sourceType: string) {
  switch (sourceType) {
    case 'pathway':
      return 'Parcours';
    case 'platform':
      return 'Plateforme';
    case 'module':
      return 'Module';
    case 'masterclass':
      return 'Masterclass';
    default:
      return sourceType;
  }
}
