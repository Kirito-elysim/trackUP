import { useEffect, useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime } from '../lib/format';
import type { IntegrationsPayload } from '../types/trackup';

export function IntegrationsPage() {
  const { token } = useAuth();
  const [payload, setPayload] = useState<IntegrationsPayload | null>(null);
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
        const data = await apiRequest<IntegrationsPayload>('/api/integrations', { token });

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
          <p className="eyebrow eyebrow-dark">Synchronisation</p>
          <h2>Intégrations</h2>
        </div>
        <div className="header-meta">
          <p className="muted">État local de la connexion Rise Up, fraîcheur des jeux de données et commandes utiles.</p>
          <span className="status-chip status-chip-soft">Lecture seule</span>
        </div>
      </div>

      {loading ? <div className="panel-card">Chargement des intégrations...</div> : null}
      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {payload ? (
        <>
          <div className="hero-panel">
            <div className="hero-copy">
              <span className="status-chip">Rise Up API</span>
              <h3>Synchronisation locale pilotée, sans écriture côté LMS.</h3>
              <p>
                `TrackUp` ne pousse rien dans `Rise Up`. Cette vue montre uniquement la santé du lien, la fraîcheur des
                données importées et les commandes Symfony disponibles pour relancer une sync.
              </p>
            </div>

            <div className="hero-stats">
              <div>
                <span>Mode</span>
                <strong>{payload.connection.mode}</strong>
              </div>
              <div>
                <span>Dernière synchro</span>
                <strong>{formatDateTime(payload.connection.lastSyncAt)}</strong>
              </div>
            </div>
          </div>

          <div className="stats-grid">
            <article className="metric-card">
              <p className="metric-label">Jeux de données</p>
              <strong className="metric-value">{payload.metrics.datasetsCount}</strong>
              <p className="metric-hint">Blocs fonctionnels suivis localement</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Blocs synchronisés</p>
              <strong className="metric-value">{payload.metrics.syncedDatasetsCount}</strong>
              <p className="metric-hint">Sources avec données effectivement présentes</p>
            </article>
            <article className="metric-card">
              <p className="metric-label">Lignes locales</p>
              <strong className="metric-value">{payload.metrics.totalRows}</strong>
              <p className="metric-hint">Total cumulé des lignes actuellement stockées</p>
            </article>
          </div>

          <div className="content-grid">
            <article className="panel-card panel-card-emphasis">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Jeux de données</p>
                  <h3>Fraîcheur par domaine</h3>
                </div>
              </div>

              <div className="stack">
                {payload.datasets.map((dataset) => (
                  <div className="list-row" key={dataset.table}>
                    <div>
                      <strong>{dataset.label}</strong>
                      <p className="muted">
                        Table principale `{dataset.table}` {dataset.secondaryCount !== null ? `· complément ${dataset.secondaryCount}` : ''}
                      </p>
                    </div>
                    <div className="list-row-meta">
                      <span>{dataset.primaryCount} lignes</span>
                      <small>{formatDateTime(dataset.lastSyncAt)}</small>
                    </div>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel-card">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Commandes</p>
                  <h3>Relance manuelle</h3>
                </div>
              </div>

              <div className="stack">
                {payload.commands.map((command) => (
                  <div className="command-card" key={command}>
                    <code>{command}</code>
                  </div>
                ))}
              </div>
            </article>
          </div>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Connexion</p>
                <h3>Paramètres exposés</h3>
              </div>
            </div>

            <div className="stack">
              <div className="list-row">
                <div>
                  <strong>Provider</strong>
                  <p className="muted">{payload.connection.provider}</p>
                </div>
                <div className="list-row-meta">
                  <span>{payload.connection.health}</span>
                  <small>Santé API locale</small>
                </div>
              </div>

              <div className="list-row">
                <div>
                  <strong>Base URL</strong>
                  <p className="muted">{payload.connection.apiBaseUrl}</p>
                </div>
                <div className="list-row-meta">
                  <span>{payload.connection.mode}</span>
                  <small>Mode d’accès</small>
                </div>
              </div>
            </div>
          </article>
        </>
      ) : null}
    </section>
  );
}
