import { useEffect, useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { DashboardPayload } from '../types/trackup';

export function DashboardPage() {
  const { token } = useAuth();
  const [dashboard, setDashboard] = useState<DashboardPayload | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const payload = await apiRequest<DashboardPayload>('/api/dashboard', { token });

        if (!cancelled) {
          setDashboard(payload);
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

  const cards = dashboard
    ? [
        {
          title: 'Apprenants suivis',
          value: dashboard.metrics.learnersCount.toString(),
          hint: `${dashboard.metrics.activeLearnersCount} actifs sur la période synchronisée`,
        },
        {
          title: 'Temps consolidé',
          value: formatDuration(dashboard.metrics.totalTrackedTime),
          hint: `${dashboard.metrics.trainingRegistrationsCount} inscriptions formation consolidées`,
        },
        {
          title: 'Progression moyenne',
          value: formatPercentage(dashboard.metrics.averageProgress),
          hint: `${dashboard.metrics.stepStatesCount} états de step enregistrés`,
        },
        {
          title: 'Présences signées',
          value: dashboard.metrics.signedAttendancesCount.toString(),
          hint: `${dashboard.metrics.sessionsCount} sessions et ${dashboard.metrics.sessionRegistrationsCount} inscriptions`,
        },
      ]
    : [];

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Pilotage</p>
          <h2>Dashboard TrackUp</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Vue consolidée des données locales synchronisées depuis Rise Up.</p>
          <span className="status-chip status-chip-soft">
            Dernière synchro {dashboard ? formatDateTime(dashboard.lastSyncAt) : 'en attente'}
          </span>
        </div>
      </div>

      {loading ? <div className="panel-card">Chargement du dashboard...</div> : null}
      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {dashboard ? (
        <>
          <div className="hero-panel">
            <div className="hero-copy">
              <span className="status-chip">Vue opérationnelle</span>
              <h3>Un cockpit clair pour les temps, la progression et la conformité.</h3>
              <p>
                TrackUp centralise les temps consolidés, les présences et les signaux d’activité pour éviter les exports
                dispersés et les contrôles difficiles à reconstituer.
              </p>
            </div>

            <div className="hero-stats">
              <div>
                <span>Formations actives</span>
                <strong>{dashboard.metrics.trainingsCount}</strong>
              </div>
              <div>
                <span>Masterclass suivies</span>
                <strong>{dashboard.metrics.sessionsCount}</strong>
              </div>
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

          <div className="content-grid">
            <article className="panel-card panel-card-emphasis">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Top formations</p>
                  <h3>Charge apprenants et temps cumulé</h3>
                </div>
              </div>

              <div className="stack">
                {dashboard.topTrainings.map((training) => (
                  <div className="list-row" key={training.id}>
                    <div>
                      <strong>{training.title}</strong>
                      <p className="muted">
                        {training.learnersCount} apprenants · {formatDuration(training.totalTime)}
                      </p>
                    </div>
                    <span className="status-chip status-chip-contrast">{formatPercentage(training.averageProgress)}</span>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel-card">
              <div className="panel-card-header">
                <div>
                  <p className="section-kicker">Activité récente</p>
                  <h3>Dernières connexions apprenants</h3>
                </div>
              </div>

              <div className="stack">
                {dashboard.recentLearners.map((learner) => (
                  <div className="list-row" key={learner.id}>
                    <div>
                      <strong>{learner.fullName}</strong>
                      <p className="muted">
                        {learner.email} · {learner.trainingCount} formations
                      </p>
                    </div>
                    <div className="list-row-meta">
                      <span>{formatDuration(learner.totalTime)}</span>
                      <small>{formatDateTime(learner.lastLoginAt)}</small>
                    </div>
                  </div>
                ))}
              </div>
            </article>
          </div>
        </>
      ) : null}
    </section>
  );
}
