import { useEffect, useState, useMemo } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Clock, Users, TrendingUp, Search, ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDuration, formatPercentage, formatDateTime } from '../lib/format';
import { LearnerSessionsModal } from '../components/LearnerSessionsModal';
import type { LearningPathDetail } from '../types/trackup';

type SortField = 'name' | 'combinedTime' | 'sessionTime' | 'expectedTime' | 'timeCompletion' | 'elearningTime' | 'expectedElearningTime' | 'elearningCompletion' | 'progress' | 'subscribedAt';
type SortDirection = 'asc' | 'desc';

export function LearningPathDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { token } = useAuth();
  const [data, setData] = useState<LearningPathDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedLearner, setSelectedLearner] = useState<{ id: number; name: string } | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [sortField, setSortField] = useState<SortField>('name');
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');

  useEffect(() => {
    if (!token || !id) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const payload = await apiRequest<LearningPathDetail>(`/api/learningpaths/${id}`, { token });

        if (!cancelled) {
          setData(payload);
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
  }, [token, id]);

  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const filteredAndSortedLearners = useMemo(() => {
    if (!data) return [];

    let filtered = data.learners.filter((learner) => {
      const query = searchQuery.toLowerCase();
      return (
        learner.fullName.toLowerCase().includes(query) ||
        (learner.email && learner.email.toLowerCase().includes(query))
      );
    });

    filtered.sort((a, b) => {
      let aValue: string | number;
      let bValue: string | number;

      switch (sortField) {
        case 'name':
          aValue = a.fullName.toLowerCase();
          bValue = b.fullName.toLowerCase();
          break;
        case 'combinedTime':
          aValue = a.sessionTime + a.elearningTime;
          bValue = b.sessionTime + b.elearningTime;
          break;
        case 'sessionTime':
          aValue = a.sessionTime;
          bValue = b.sessionTime;
          break;
        case 'expectedTime':
          aValue = a.expectedTime;
          bValue = b.expectedTime;
          break;
        case 'timeCompletion':
          aValue = a.expectedTime > 0 ? (a.sessionTime / a.expectedTime) * 100 : 0;
          bValue = b.expectedTime > 0 ? (b.sessionTime / b.expectedTime) * 100 : 0;
          break;
        case 'elearningTime':
          aValue = a.elearningTime;
          bValue = b.elearningTime;
          break;
        case 'expectedElearningTime':
          aValue = a.expectedElearningTime;
          bValue = b.expectedElearningTime;
          break;
        case 'elearningCompletion':
          aValue = a.expectedElearningTime > 0 ? (a.elearningTime / a.expectedElearningTime) * 100 : 0;
          bValue = b.expectedElearningTime > 0 ? (b.elearningTime / b.expectedElearningTime) * 100 : 0;
          break;
        case 'progress':
          aValue = a.averageProgress;
          bValue = b.averageProgress;
          break;
        case 'subscribedAt':
          aValue = a.subscribedAt || '';
          bValue = b.subscribedAt || '';
          break;
        default:
          return 0;
      }

      if (aValue < bValue) return sortDirection === 'asc' ? -1 : 1;
      if (aValue > bValue) return sortDirection === 'asc' ? 1 : -1;
      return 0;
    });

    return filtered;
  }, [data, searchQuery, sortField, sortDirection]);

  const SortIcon = ({ field }: { field: SortField }) => {
    if (sortField !== field) return <ArrowUpDown size={14} className="sort-icon inactive" />;
    return sortDirection === 'asc' ? (
      <ArrowUp size={14} className="sort-icon active" />
    ) : (
      <ArrowDown size={14} className="sort-icon active" />
    );
  };

  return (
    <section className="page-section">
      <div className="page-header">
        <button className="back-button" onClick={() => navigate('/dashboard')} type="button">
          <ArrowLeft size={20} />
          Retour au tableau de bord
        </button>
        {data ? (
          <>
            <h2>{data.learningPath.title}</h2>
            {data.learningPath.description ? <p className="muted">{data.learningPath.description}</p> : null}
          </>
        ) : null}
      </div>

      {loading ? <div className="panel-card">Chargement...</div> : null}
      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {data ? (
        <>
          <div className="learning-path-overview">
            <div className="overview-card">
              <div className="overview-icon" style={{ background: 'linear-gradient(135deg, #5B8DEE 0%, #0063F7 100%)' }}>
                <Users size={24} color="white" />
              </div>
              <div className="overview-content">
                <p className="overview-label">Apprenants inscrits</p>
                <strong className="overview-value">{data.learningPath.learnerCount}</strong>
              </div>
            </div>

            <div className="overview-card">
              <div className="overview-icon" style={{ background: 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)' }}>
                <Clock size={24} color="white" />
              </div>
              <div className="overview-content">
                <p className="overview-label">Temps total</p>
                <strong className="overview-value">{formatDuration(data.learningPath.totalTime)}</strong>
              </div>
            </div>

            <div className="overview-card">
              <div className="overview-icon" style={{ background: 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)' }}>
                <TrendingUp size={24} color="white" />
              </div>
              <div className="overview-content">
                <p className="overview-label">Progression moyenne</p>
                <strong className="overview-value">{formatPercentage(data.learningPath.averageProgress)}</strong>
              </div>
            </div>
          </div>

          <div className="learners-section">
            <div className="section-header">
              <h3>Temps passé par apprenant</h3>
              <p className="muted">{filteredAndSortedLearners.length} apprenants ({data.learners.length} total)</p>
            </div>

            <div className="search-bar">
              <Search size={18} className="search-icon" />
              <input
                type="text"
                className="search-input"
                placeholder="Rechercher un apprenant par nom ou email..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
            </div>

            <div className="learners-table-container">
              <table className="learners-table">
                <thead>
                  <tr>
                    <th className="sortable-header" onClick={() => handleSort('name')}>
                      <div className="header-content">
                        <span>Apprenant</span>
                        <SortIcon field="name" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('combinedTime')}>
                      <div className="header-content">
                        <span>Temps passé</span>
                        <SortIcon field="combinedTime" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('sessionTime')}>
                      <div className="header-content">
                        <span>Temps sessions</span>
                        <SortIcon field="sessionTime" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('expectedTime')}>
                      <div className="header-content">
                        <span>Temps prévu sessions</span>
                        <SortIcon field="expectedTime" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('timeCompletion')}>
                      <div className="header-content">
                        <span>Completion sessions</span>
                        <SortIcon field="timeCompletion" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('elearningTime')}>
                      <div className="header-content">
                        <span>Temps e-learning</span>
                        <SortIcon field="elearningTime" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('expectedElearningTime')}>
                      <div className="header-content">
                        <span>Temps prévu e-learning</span>
                        <SortIcon field="expectedElearningTime" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('elearningCompletion')}>
                      <div className="header-content">
                        <span>Completion e-learning</span>
                        <SortIcon field="elearningCompletion" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('progress')}>
                      <div className="header-content">
                        <span>Progression</span>
                        <SortIcon field="progress" />
                      </div>
                    </th>
                    <th className="sortable-header" onClick={() => handleSort('subscribedAt')}>
                      <div className="header-content">
                        <span>Date d'inscription</span>
                        <SortIcon field="subscribedAt" />
                      </div>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {filteredAndSortedLearners.map((learner) => (
                    <tr
                      key={learner.id}
                      onClick={() => setSelectedLearner({ id: learner.learnerId, name: learner.fullName })}
                    >
                      <td>
                        <div className="learner-info">
                          <div className="learner-avatar">
                            {learner.fullName.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <strong>{learner.fullName}</strong>
                            <p className="learner-email">{learner.email}</p>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div className="time-cell">
                          <Clock size={16} />
                          <strong>{formatDuration(learner.sessionTime + learner.elearningTime)}</strong>
                        </div>
                      </td>
                      <td>
                        <div className="time-cell">
                          <Clock size={16} />
                          <strong>{formatDuration(learner.sessionTime)}</strong>
                        </div>
                      </td>
                      <td>
                        <div className="time-cell">
                          <Clock size={16} />
                          <strong>{formatDuration(learner.expectedTime)}</strong>
                        </div>
                      </td>
                      <td>
                        <div className="completion-cell">
                          <div className="progress-bar-small">
                            <div
                              className="progress-fill-small"
                              style={{ 
                                width: `${Math.min(learner.expectedTime > 0 ? (learner.sessionTime / learner.expectedTime) * 100 : 0, 100)}%`,
                                background: learner.expectedTime > 0 && (learner.sessionTime / learner.expectedTime) >= 0.8 
                                  ? 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)' 
                                  : 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)'
                              }}
                            />
                          </div>
                          <span className="progress-text">
                            {learner.expectedTime > 0 
                              ? formatPercentage((learner.sessionTime / learner.expectedTime) * 100)
                              : '0%'
                            }
                          </span>
                        </div>
                      </td>
                      <td>
                        <div className="time-cell">
                          <Clock size={16} />
                          <strong>{formatDuration(learner.elearningTime)}</strong>
                        </div>
                      </td>
                      <td>
                        <div className="time-cell">
                          <Clock size={16} />
                          <strong>{formatDuration(learner.expectedElearningTime)}</strong>
                        </div>
                      </td>
                      <td>
                        <div className="completion-cell">
                          <div className="progress-bar-small">
                            <div
                              className="progress-fill-small"
                              style={{ 
                                width: `${Math.min(learner.expectedElearningTime > 0 ? (learner.elearningTime / learner.expectedElearningTime) * 100 : 0, 100)}%`,
                                background: learner.expectedElearningTime > 0 && (learner.elearningTime / learner.expectedElearningTime) >= 0.8 
                                  ? 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)' 
                                  : 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)'
                              }}
                            />
                          </div>
                          <span className="progress-text">
                            {learner.expectedElearningTime > 0 
                              ? formatPercentage((learner.elearningTime / learner.expectedElearningTime) * 100)
                              : '0%'
                            }
                          </span>
                        </div>
                      </td>
                      <td>
                        <div className="progress-cell">
                          <div className="progress-bar-small">
                            <div
                              className="progress-fill-small"
                              style={{ width: `${Math.min((learner.progress ?? 0) * 100, 100)}%` }}
                            />
                          </div>
                          <span className="progress-text">{formatPercentage(learner.progress ?? 0)}</span>
                        </div>
                      </td>
                      <td>
                        <span className="date-cell">{learner.subscribedAt ? formatDateTime(learner.subscribedAt) : 'N/A'}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {data.learners.length === 0 ? (
                <div className="empty-state">
                  <Users size={48} />
                  <p>Aucun apprenant inscrit à ce parcours</p>
                </div>
              ) : null}
            </div>
          </div>
        </>
      ) : null}

      {selectedLearner && id ? (
        <LearnerSessionsModal
          learningPathId={parseInt(id, 10)}
          learnerId={selectedLearner.id}
          learnerName={selectedLearner.name}
          onClose={() => setSelectedLearner(null)}
        />
      ) : null}
    </section>
  );
}
