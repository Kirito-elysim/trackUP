import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { clampPercentage, formatDuration, formatPercentage, formatDateTime } from '../lib/format';
import { Search, Clock, TrendingUp, BookOpen, User, Calendar, CheckCircle, XCircle, Activity, X, Award, Target, Zap, ChevronLeft, ChevronRight } from 'lucide-react';
import type { LearnerDetail, LearnerSummary } from '../types/trackup';

export function LearnersPage() {
  const { token } = useAuth();
  const [searchQuery, setSearchQuery] = useState('');
  const deferredQuery = useDeferredValue(searchQuery);
  const [learners, setLearners] = useState<LearnerSummary[]>([]);
  const [selectedLearnerId, setSelectedLearnerId] = useState<number | null>(null);
  const [selectedLearner, setSelectedLearner] = useState<LearnerDetail | null>(null);
  const [showSearchResults, setShowSearchResults] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'formations' | 'sessions' | 'activity'>('formations');
  const [upcomingPage, setUpcomingPage] = useState(1);
  const upcomingPageSize = 4;

  const upcomingSessions = useMemo(() => {
    if (!selectedLearner) {
      return [];
    }

    const now = Date.now();

    return selectedLearner.sessionRegistrations
      .filter((session) => session.startAt && new Date(session.startAt).getTime() > now)
      .sort((a, b) => new Date(a.startAt!).getTime() - new Date(b.startAt!).getTime());
  }, [selectedLearner]);

  const upcomingTotalPages = Math.max(1, Math.ceil(upcomingSessions.length / upcomingPageSize));
  const upcomingPageStartIndex = (upcomingPage - 1) * upcomingPageSize;
  const upcomingPageItems = upcomingSessions.slice(upcomingPageStartIndex, upcomingPageStartIndex + upcomingPageSize);

  useEffect(() => {
    setUpcomingPage(1);
  }, [selectedLearnerId]);

  useEffect(() => {
    if (upcomingPage > upcomingTotalPages) {
      setUpcomingPage(upcomingTotalPages);
    }
  }, [upcomingPage, upcomingTotalPages]);

  useEffect(() => {
    if (!token || deferredQuery.trim().length < 2) {
      setLearners([]);
      return;
    }

    let cancelled = false;

    const loadLearners = async () => {
      setError(null);

      const params = new URLSearchParams({ 
        limit: '20',
        q: deferredQuery.trim()
      });

      try {
        const payload = await apiRequest<LearnerSummary[]>(`/api/learners?${params.toString()}`, { token });

        if (!cancelled) {
          setLearners(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Recherche impossible.');
          setLearners([]);
        }
      }
    };

    void loadLearners();

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, token]);

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

  const handleSelectLearner = (learnerId: number) => {
    setSelectedLearnerId(learnerId);
    setShowSearchResults(false);
    setSearchQuery('');
  };

  return (
    <div className="page-container">
      <div className="learner-page-header">
        <h1>Apprenants</h1>
        <p className="page-subtitle">Recherchez et consultez les détails de vos apprenants</p>
      </div>

      <div className="learner-search-section">
        <div className="learner-search-container">
          <Search size={24} className="search-icon-large" />
          <input
            type="text"
            className="search-input-large"
            placeholder="Rechercher un apprenant par nom ou email..."
            value={searchQuery}
            onChange={(e) => {
              setSearchQuery(e.target.value);
              setShowSearchResults(e.target.value.length >= 2);
            }}
            onFocus={() => setShowSearchResults(searchQuery.length >= 2)}
          />
          {searchQuery.length > 0 && (
            <button
              className="search-clear-btn"
              onClick={() => {
                setSearchQuery('');
                setShowSearchResults(false);
              }}
              type="button"
            >
              <X size={18} />
            </button>
          )}
        </div>

        {showSearchResults && (
          <div className="learner-search-dropdown">
            {learners.length > 0 ? (
              <div className="search-results-list">
                {learners.map((learner) => (
                  <button
                    key={learner.id}
                    className="search-result-item"
                    onClick={() => handleSelectLearner(learner.id)}
                    type="button"
                  >
                    <div className="result-avatar">
                      <User size={24} />
                    </div>
                    <div className="result-info">
                      <strong>{learner.fullName}</strong>
                      <span>{learner.email}</span>
                    </div>
                    <div className="result-meta">
                      <span className="meta-badge">{learner.state}</span>
                    </div>
                  </button>
                ))}
              </div>
            ) : (
              <div className="search-empty">
                <User size={32} />
                <p>{searchQuery.length < 2 ? 'Tapez au moins 2 caractères pour rechercher' : 'Aucun apprenant trouvé'}</p>
              </div>
            )}
          </div>
        )}
      </div>

      {error && <div className="error-state">{error}</div>}

      {!selectedLearner && !detailLoading && (
        <div className="learner-empty-state">
          <div className="empty-icon">
            <User size={64} />
          </div>
          <h3>Sélectionnez un apprenant</h3>
          <p>Utilisez la barre de recherche ci-dessus pour trouver et afficher les détails d'un apprenant</p>
        </div>
      )}

      {detailLoading && (
        <div className="loading-state" style={{ marginTop: '3rem' }}>Chargement des détails...</div>
      )}

      {selectedLearner && (
        <div className="learner-detail-content">
          {/* Profil Header */}
          <div className="learner-profile-card">
            <div className="learner-profile-avatar">
              <User size={40} />
            </div>
            <div className="learner-profile-info">
              <h2>{selectedLearner.learner.fullName}</h2>
              <p className="learner-profile-role">{selectedLearner.learner.state}</p>
              <p className="learner-profile-email">{selectedLearner.learner.email}</p>
            </div>
            <div className="learner-profile-meta">
              {selectedLearner.learner.lastActivityAt && (
                <div className="meta-item">
                  <Activity size={16} />
                  <span>Dernière activité : {formatDateTime(selectedLearner.learner.lastActivityAt)}</span>
                </div>
              )}
              {selectedLearner.learner.activatedAt && (
                <div className="meta-item">
                  <CheckCircle size={16} />
                  <span>Activé le : {formatDateTime(selectedLearner.learner.activatedAt)}</span>
                </div>
              )}
            </div>
          </div>

          {/* KPIs Section */}
          <div className="kpi-grid">
            <div className="kpi-card" style={{ borderLeftColor: '#FF6B9D' }}>
              <div className="kpi-header">
                <div className="kpi-icon" style={{ background: 'linear-gradient(135deg, #FF6B9D 0%, #C239B3 100%)' }}>
                  <Clock size={20} color="white" />
                </div>
                <span className="kpi-label">Temps du groupe</span>
              </div>
              <div className="kpi-value">{formatDuration(selectedLearner.learner.groupTotalTime)}</div>
              <div className="kpi-progress">
                <div className="kpi-progress-bar">
                  <div 
                    className="kpi-progress-fill" 
                    style={{ 
                      width: `${Math.min((selectedLearner.learner.groupTotalTime / (100 * 60)) * 100, 100)}%`,
                      background: 'linear-gradient(90deg, #FF6B9D 0%, #C239B3 100%)'
                    }}
                  />
                </div>
                <span className="kpi-hint">{selectedLearner.learner.groupName || 'Aucun groupe'}</span>
              </div>
            </div>

            <div className="kpi-card" style={{ borderLeftColor: '#5B8DEE' }}>
              <div className="kpi-header">
                <div className="kpi-icon" style={{ background: 'linear-gradient(135deg, #5B8DEE 0%, #0063F7 100%)' }}>
                  <TrendingUp size={20} color="white" />
                </div>
                <span className="kpi-label">Progression moyenne</span>
              </div>
              <div className="kpi-value">{formatPercentage(selectedLearner.learner.averageProgress)}</div>
              <div className="kpi-progress">
                <div className="kpi-progress-bar">
                  <div 
                    className="kpi-progress-fill" 
                    style={{ 
                      width: `${clampPercentage(selectedLearner.learner.averageProgress)}%`,
                      background: 'linear-gradient(90deg, #5B8DEE 0%, #0063F7 100%)'
                    }}
                  />
                </div>
                <span className="kpi-hint">{selectedLearner.trainingRegistrations.length} formations</span>
              </div>
            </div>

            <div className="kpi-card" style={{ borderLeftColor: '#00A67E' }}>
              <div className="kpi-header">
                <div className="kpi-icon" style={{ background: 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)' }}>
                  <Target size={20} color="white" />
                </div>
                <span className="kpi-label">Taux d'assiduité</span>
              </div>
              <div className="kpi-value">
                {selectedLearner.learner.sessionRegistrationCount > 0 
                  ? formatPercentage((selectedLearner.learner.signedAttendanceCount / selectedLearner.learner.sessionRegistrationCount) * 100)
                  : '0%'
                }
              </div>
              <div className="kpi-progress">
                <div className="kpi-progress-bar">
                  <div 
                    className="kpi-progress-fill" 
                    style={{ 
                      width: selectedLearner.learner.sessionRegistrationCount > 0 
                        ? `${(selectedLearner.learner.signedAttendanceCount / selectedLearner.learner.sessionRegistrationCount) * 100}%`
                        : '0%',
                      background: 'linear-gradient(90deg, #34C4AC 0%, #00A67E 100%)'
                    }}
                  />
                </div>
                <span className="kpi-hint">
                  {selectedLearner.learner.signedAttendanceCount} / {selectedLearner.learner.sessionRegistrationCount} sessions
                </span>
              </div>
            </div>

            <div className="kpi-card" style={{ borderLeftColor: '#FFB946' }}>
              <div className="kpi-header">
                <div className="kpi-icon" style={{ background: 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)' }}>
                  <Award size={20} color="white" />
                </div>
                <span className="kpi-label">Formations complètes</span>
              </div>
              <div className="kpi-value">
                {selectedLearner.trainingRegistrations.filter(t => (t.progress ?? 0) >= 100).length}
              </div>
              <div className="kpi-progress">
                <div className="kpi-progress-bar">
                  <div 
                    className="kpi-progress-fill" 
                    style={{ 
                      width: selectedLearner.trainingRegistrations.length > 0
                        ? `${(selectedLearner.trainingRegistrations.filter(t => (t.progress ?? 0) >= 100).length / selectedLearner.trainingRegistrations.length) * 100}%`
                        : '0%',
                      background: 'linear-gradient(90deg, #FFB946 0%, #FF9A00 100%)'
                    }}
                  />
                </div>
                <span className="kpi-hint">
                  {selectedLearner.trainingRegistrations.filter(t => (t.progress ?? 0) >= 100).length} / {selectedLearner.trainingRegistrations.length}
                </span>
              </div>
            </div>
          </div>

          {/* Sessions à venir */}
          {upcomingSessions.length > 0 && (
            <div className="upcoming-sessions-section">
              <div className="section-title">
                <Zap size={20} color="#0063F7" />
                <h3>Sessions à venir</h3>
                <span className="badge-count">
                  {upcomingSessions.length}
                </span>
                {upcomingTotalPages > 1 && (
                  <div className="upcoming-pagination">
                    <button
                      type="button"
                      className="upcoming-pagination-button"
                      onClick={() => setUpcomingPage((page) => Math.max(1, page - 1))}
                      disabled={upcomingPage === 1}
                      aria-label="Page précédente"
                    >
                      <ChevronLeft size={18} />
                    </button>
                    <span className="upcoming-pagination-status">
                      {upcomingPage} / {upcomingTotalPages}
                    </span>
                    <button
                      type="button"
                      className="upcoming-pagination-button"
                      onClick={() => setUpcomingPage((page) => Math.min(upcomingTotalPages, page + 1))}
                      disabled={upcomingPage === upcomingTotalPages}
                      aria-label="Page suivante"
                    >
                      <ChevronRight size={18} />
                    </button>
                  </div>
                )}
              </div>
              <div className="upcoming-sessions-grid">
                {upcomingPageItems.map((session) => (
                    <div key={session.id} className="upcoming-session-card">
                      <div className="session-date-badge">
                        <Calendar size={16} />
                        <span>{new Date(session.startAt!).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}</span>
                      </div>
                      <h4>{session.trainingTitle || 'Session'}</h4>
                      <p className="session-time">
                        {new Date(session.startAt!).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
                        {session.endAt && ` - ${new Date(session.endAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`}
                      </p>
                      <span className="session-type-badge">{session.sessionType || 'Session'}</span>
                    </div>
                ))}
              </div>
            </div>
          )}

          <div className="learner-tabs-section">
            <div className="tabs-header">
              <button
                className={`tab-button ${activeTab === 'formations' ? 'tab-button-active' : ''}`}
                onClick={() => setActiveTab('formations')}
                type="button"
              >
                <BookOpen size={18} />
                <span>Formations ({selectedLearner.trainingRegistrations.length})</span>
              </button>
              <button
                className={`tab-button ${activeTab === 'sessions' ? 'tab-button-active' : ''}`}
                onClick={() => setActiveTab('sessions')}
                type="button"
              >
                <Calendar size={18} />
                <span>Sessions ({selectedLearner.sessionRegistrations.length})</span>
              </button>
              <button
                className={`tab-button ${activeTab === 'activity' ? 'tab-button-active' : ''}`}
                onClick={() => setActiveTab('activity')}
                type="button"
              >
                <Activity size={18} />
                <span>Activité récente ({selectedLearner.recentActivities.length})</span>
              </button>
            </div>

            <div className="tabs-content">
              {activeTab === 'formations' && (
                <div className="trainings-grid">
                  {selectedLearner.trainingRegistrations.map((registration) => (
                    <div key={registration.id} className="training-card">
                      <div className="training-image">
                        <BookOpen size={32} color="white" />
                        <div className="training-badge">{formatPercentage(registration.progress ?? 0)}</div>
                      </div>
                      <div className="training-content">
                        <h4>{registration.trainingTitle}</h4>
                        <div className="training-meta">
                          <Clock size={14} />
                          <span>{formatDuration(registration.totalTime)}</span>
                        </div>
                        <div className="training-meta" style={{ marginTop: '4px' }}>
                          <span className="training-state-badge">{registration.state}</span>
                        </div>
                        <div className="training-progress">
                          <div className="training-progress-bar">
                            <div 
                              className="training-progress-fill"
                              style={{ width: `${registration.progress ?? 0}%` }}
                            />
                          </div>
                          <span className="training-progress-value">{formatPercentage(registration.progress ?? 0)}</span>
                        </div>
                        {registration.score !== null && (
                          <p className="training-score">Score: {registration.score}%</p>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}

              {activeTab === 'sessions' && (
                <div className="sessions-list">
                  {selectedLearner.sessionRegistrations.map((session) => (
                    <div key={session.id} className="session-card">
                      <div className="session-header">
                        <div className="session-info">
                          <h4>{session.trainingTitle || 'Session sans formation'}</h4>
                          <p className="session-type">{session.sessionType || 'Session'}</p>
                        </div>
                        <div className="session-status">
                          {session.signedCount > 0 ? (
                            <div className="status-badge status-success">
                              <CheckCircle size={16} />
                              <span>{session.signedCount} signature{session.signedCount > 1 ? 's' : ''}</span>
                            </div>
                          ) : (
                            <div className="status-badge status-neutral">
                              <XCircle size={16} />
                              <span>Non signé</span>
                            </div>
                          )}
                        </div>
                      </div>
                      <div className="session-details">
                        <div className="session-detail-item">
                          <Calendar size={16} />
                          <span>
                            {session.startAt ? formatDateTime(session.startAt) : 'Date non définie'}
                            {session.endAt && ` - ${formatDateTime(session.endAt)}`}
                          </span>
                        </div>
                        {session.eduDuration && (
                          <div className="session-detail-item">
                            <Clock size={16} />
                            <span>Durée: {formatDuration(session.eduDuration)}</span>
                          </div>
                        )}
                        {session.attended !== null && (
                          <div className="session-detail-item">
                            <span className={`session-state-badge ${session.attended ? 'status-success' : 'status-error'}`}>
                              {session.attended ? 'Présent' : 'Absent'}
                            </span>
                          </div>
                        )}
                        <div className="session-detail-item">
                          <span className="session-state-badge">{session.state}</span>
                        </div>
                      </div>
                    </div>
                  ))}
                  {selectedLearner.sessionRegistrations.length === 0 && (
                    <p className="muted" style={{ textAlign: 'center', padding: '2rem' }}>
                      Aucune session enregistrée
                    </p>
                  )}
                </div>
              )}

              {activeTab === 'activity' && (
                <div className="activity-list">
                  {selectedLearner.recentActivities.map((activity) => (
                    <div key={activity.id} className="activity-card">
                      <div className="activity-icon">
                        <Activity size={20} color="#FF6B9D" />
                      </div>
                      <div className="activity-content">
                        <h4>{activity.stepTitle}</h4>
                        <p className="activity-path">
                          {activity.trainingTitle} › {activity.moduleTitle}
                        </p>
                        <div className="activity-meta">
                          <span className="activity-type">{activity.stepType || 'Step'}</span>
                          <span className="activity-time">{formatDuration(activity.totalTime ?? activity.timeSpent ?? 0)}</span>
                          <span className="activity-date">{formatDateTime(activity.activityAt || '')}</span>
                        </div>
                        {activity.score !== null && (
                          <div className="activity-score">Score: {activity.score}%</div>
                        )}
                      </div>
                      <div className="activity-state">
                        <span className={`state-badge state-${activity.state.toLowerCase()}`}>
                          {activity.state}
                        </span>
                      </div>
                    </div>
                  ))}
                  {selectedLearner.recentActivities.length === 0 && (
                    <p className="muted" style={{ textAlign: 'center', padding: '2rem' }}>
                      Aucune activité récente
                    </p>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
