import { useEffect, useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { ExportsPayload } from '../types/trackup';

export function ExportsPage() {
  const { token } = useAuth();
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

      try {
        const data = await apiRequest<ExportsPayload>('/api/exports', { token });

        if (!cancelled) {
          setPayload(data);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
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
  }, [token]);

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Conformité</p>
          <h2>Exports</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Vue de préparation des exports PDF/CSV et des signaux de complétude conformité.</p>
          <span className="status-chip status-chip-soft">Préparation locale</span>
        </div>
      </div>

      {loading ? <div className="panel-card">Chargement des données d’export...</div> : null}
      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {payload ? (
        <>
          <div className="hero-panel">
            <div className="hero-copy">
              <span className="status-chip">Exports pilotés</span>
              <h3>Identifier rapidement ce qui est prêt à sortir et ce qui reste à sécuriser.</h3>
              <p>
                Cette vue prépare les exports apprenant et formation à partir des temps consolidés, des signatures
                disponibles et des cas de présence sans preuve complète.
              </p>
            </div>

            <div className="hero-stats">
              <div>
                <span>Temps suivis</span>
                <strong>{formatDuration(payload.metrics.trackedTimeTotal)}</strong>
              </div>
              <div>
                <span>Signatures remontées</span>
                <strong>{payload.metrics.signedRegistrationsCount}</strong>
              </div>
            </div>
          </div>

          <div className="stats-grid">
            <article className="metric-card">
              <p className="metric-label">Apprenants exportables</p>
              <strong className="metric-value">{payload.metrics.learnersReadyCount}</strong>
              <p className="metric-hint">Inscriptions avec temps réellement consolidé</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Présences signées</p>
              <strong className="metric-value">{payload.metrics.signedRegistrationsCount}</strong>
              <p className="metric-hint">Pièces de présence prêtes pour les justificatifs</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Alertes conformité</p>
              <strong className="metric-value">{payload.metrics.sessionsWithoutSignatureCount}</strong>
              <p className="metric-hint">Présences détectées sans signature confirmée</p>
            </article>
          </div>

          <div className="content-grid">
            <article className="panel-card panel-card-emphasis">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Exports apprenant</p>
                  <h3>Profils prioritaires</h3>
                </div>
              </div>

              <div className="stack">
                {payload.learnerExports.map((learner) => (
                  <div className="list-row" key={learner.id}>
                    <div>
                      <strong>{learner.fullName}</strong>
                      <p className="muted">
                        {learner.trainingCount} formations · {learner.signedCount} signature(s)
                      </p>
                    </div>
                    <div className="list-row-meta">
                      <span>{formatDuration(learner.totalTime)}</span>
                      <small>{formatPercentage(learner.averageProgress)}</small>
                    </div>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel-card">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Exports formation</p>
                  <h3>Parcours les plus denses</h3>
                </div>
              </div>

              <div className="stack">
                {payload.trainingExports.map((training) => (
                  <div className="list-row" key={training.id}>
                    <div>
                      <strong>{training.title}</strong>
                      <p className="muted">
                        {training.learnersCount} apprenants · {training.sessionCount} session(s)
                      </p>
                    </div>
                    <div className="list-row-meta">
                      <span>{formatDuration(training.totalTime)}</span>
                      <small>{formatPercentage(training.averageProgress)}</small>
                    </div>
                  </div>
                ))}
              </div>
            </article>
          </div>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Alertes</p>
                <h3>Présences à vérifier avant export</h3>
              </div>
            </div>

            <div className="stack">
              {payload.complianceAlerts.length === 0 ? (
                <p className="muted">Aucune alerte détectée.</p>
              ) : (
                payload.complianceAlerts.map((alert, index) => (
                  <div className="list-row" key={`${alert.learnerId}-${index}`}>
                    <div>
                      <strong>{alert.fullName}</strong>
                      <p className="muted">
                        {alert.trainingTitle ?? 'Formation non rattachée'} · {formatDateTime(alert.sessionStartAt)}
                      </p>
                    </div>
                    <div className="list-row-meta">
                      <span>{alert.unsignedAttendances} présence(s)</span>
                      <small>Sans signature</small>
                    </div>
                  </div>
                ))
              )}
            </div>
          </article>
        </>
      ) : null}
    </section>
  );
}
