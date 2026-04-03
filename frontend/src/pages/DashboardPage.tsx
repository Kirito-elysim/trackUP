import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Users, BookOpen, Clock } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDuration, formatPercentage } from '../lib/format';
import type { DashboardPayload } from '../types/trackup';

export function DashboardPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
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

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Tableau de bord</p>
          <h2>Bonjour, voici votre tableau de bord !</h2>
        </div>
      </div>

      {loading ? <div className="panel-card">Chargement du dashboard...</div> : null}
      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {dashboard ? (
        <>
          <div className="dashboard-metrics">
            <div className="metric-card-new">
              <div className="metric-icon" style={{ background: 'linear-gradient(135deg, #FF0F7B 0%, #FF6B9D 100%)' }}>
                <BookOpen size={24} color="white" />
              </div>
              <div className="metric-content">
                <p className="metric-label-new">Formations</p>
                <strong className="metric-value-new">{dashboard.metrics.trainingsCount}</strong>
              </div>
            </div>

            <div className="metric-card-new">
              <div className="metric-icon" style={{ background: 'linear-gradient(135deg, #FF0F7B 0%, #FF6B9D 100%)' }}>
                <Users size={24} color="white" />
              </div>
              <div className="metric-content">
                <p className="metric-label-new">Parcours</p>
                <strong className="metric-value-new">{dashboard.metrics.learningPathsCount}</strong>
              </div>
            </div>

            <div className="metric-card-new">
              <div className="metric-icon" style={{ background: 'linear-gradient(135deg, #FF0F7B 0%, #FF6B9D 100%)' }}>
                <Users size={24} color="white" />
              </div>
              <div className="metric-content">
                <p className="metric-label-new">Apprenants</p>
                <strong className="metric-value-new">{dashboard.metrics.learnersCount}</strong>
              </div>
            </div>

            <div className="metric-card-new">
              <div className="metric-icon" style={{ background: 'linear-gradient(135deg, #FF0F7B 0%, #FF6B9D 100%)' }}>
                <Clock size={24} color="white" />
              </div>
              <div className="metric-content">
                <p className="metric-label-new">Temps total actif de formation</p>
                <strong className="metric-value-new">{formatDuration(dashboard.metrics.totalYearTime)}</strong>
                <p className="metric-hint-new">Parcours Manager Efficace</p>
              </div>
            </div>
          </div>

          <div className="dashboard-section">
            <div className="section-header">
              <h3>Parcours</h3>
            </div>

            <div className="learning-paths-grid">
              {dashboard.learningPaths.map((path) => (
                <article
                  key={path.id}
                  className="learning-path-card"
                  onClick={() => navigate(`/learningpaths/${path.id}`)}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      navigate(`/learningpaths/${path.id}`);
                    }
                  }}
                >
                  <div className="path-image">
                    {path.imageUrl ? (
                      <img src={path.imageUrl} alt={path.title} />
                    ) : (
                      <div className="path-image-placeholder">
                        <BookOpen size={32} />
                      </div>
                    )}
                  </div>
                  <div className="path-content">
                    <h4>{path.title}</h4>
                    <div className="path-meta">
                      <span className="path-meta-item">
                        <Clock size={14} />
                        Dernière session {formatDuration(path.totalTime)}
                      </span>
                    </div>
                    <div className="path-stats">
                      <div className="path-stat">
                        <strong>{formatDuration(path.totalTime)}</strong>
                        <span>Temps passé</span>
                      </div>
                      <div className="path-stat">
                        <strong>{path.masterclassCount}</strong>
                        <span>Sessions</span>
                      </div>
                      <div className="path-stat">
                        <strong>{formatPercentage(path.averageProgress)}</strong>
                        <span>Progression</span>
                      </div>
                    </div>
                    <div className="path-progress">
                      <div className="progress-bar">
                        <div
                          className="progress-fill"
                          style={{ width: `${Math.min(path.averageProgress * 100, 100)}%` }}
                        />
                      </div>
                      <span className="progress-label">{formatPercentage(path.averageProgress)}</span>
                    </div>
                  </div>
                </article>
              ))}
            </div>
          </div>

          <div className="dashboard-time-section">
            <div className="section-header">
              <h3>Temps total par formation</h3>
            </div>
            <div className="time-chart">
              {dashboard.topTrainings.slice(0, 3).map((training, index) => (
                <div key={training.id} className="time-bar-item">
                  <div className="time-bar-label">
                    <strong>{formatDuration(training.totalTime)}</strong>
                    <span>{training.title}</span>
                  </div>
                  <div className="time-bar">
                    <div
                      className="time-bar-fill"
                      style={{
                        width: `${(training.totalTime / (dashboard.topTrainings[0]?.totalTime || 1)) * 100}%`,
                        background:
                          index === 0
                            ? 'linear-gradient(90deg, #5B8DEE 0%, #0063F7 100%)'
                            : index === 1
                              ? 'linear-gradient(90deg, #FFB946 0%, #FF9A00 100%)'
                              : 'linear-gradient(90deg, #34C4AC 0%, #00A67E 100%)',
                      }}
                    />
                  </div>
                </div>
              ))}
            </div>
          </div>
        </>
      ) : null}
    </section>
  );
}
