import { useDeferredValue, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDuration, formatPercentage } from '../lib/format';
import { Clock, Users, BookOpen, Search, TrendingUp, Eye, X } from 'lucide-react';
import type { LearningPathSummary, LearningPathDetail } from '../types/trackup';

export function LearningPathsPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');
  const deferredQuery = useDeferredValue(query);
  const [learningPaths, setLearningPaths] = useState<LearningPathSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedPathDetail, setSelectedPathDetail] = useState<LearningPathDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const loadLearningPaths = async () => {
      setLoading(true);
      setError(null);

      const params = new URLSearchParams({ limit: '100' });
      if (deferredQuery.trim() !== '') {
        params.set('q', deferredQuery.trim());
      }

      try {
        const payload = await apiRequest<LearningPathSummary[]>(`/api/learningpaths?${params.toString()}`, { token });

        if (cancelled) {
          return;
        }

        setLearningPaths(payload);
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement des parcours impossible.');
          setLearningPaths([]);
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    void loadLearningPaths();

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, token]);

  const loadPathDetail = async (pathId: number) => {
    if (!token) return;

    setDetailLoading(true);
    try {
      const detail = await apiRequest<LearningPathDetail>(`/api/learningpaths/${pathId}`, { token });
      setSelectedPathDetail(detail);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Impossible de charger les détails du parcours');
    } finally {
      setDetailLoading(false);
    }
  };

  const closeModal = () => {
    setSelectedPathDetail(null);
  };


  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Pilotage</p>
          <h2>Parcours de formation</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Explorez tous les parcours de formation synchronisés depuis Rise Up</p>
          <span className="status-chip status-chip-soft">{learningPaths.length} parcours disponibles</span>
        </div>
      </div>

      <div className="panel-card" style={{ marginBottom: '24px' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px', padding: '4px' }}>
          <Search size={20} style={{ color: '#9ca3af' }} />
          <input
            type="text"
            placeholder="Rechercher un parcours par titre ou référence..."
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            style={{
              flex: 1,
              border: 'none',
              outline: 'none',
              fontSize: '15px',
              padding: '8px 4px',
            }}
          />
        </div>
      </div>

      {error ? <div className="panel-card error-panel">{error}</div> : null}

      {loading ? (
        <div className="panel-card" style={{ textAlign: 'center', padding: '48px' }}>
          <p className="muted">Chargement des parcours...</p>
        </div>
      ) : learningPaths.length === 0 ? (
        <div className="panel-card empty-state" style={{ textAlign: 'center', padding: '48px' }}>
          <BookOpen size={48} style={{ margin: '0 auto 16px', color: '#9ca3af' }} />
          <h3>Aucun parcours trouvé</h3>
          <p className="muted">Aucun parcours ne correspond à votre recherche</p>
        </div>
      ) : (
        <div className="learning-paths-grid">
          {learningPaths.map((path) => (
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
                    <BookOpen size={40} />
                  </div>
                )}
              </div>
              <div className="path-content">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', marginBottom: '8px' }}>
                  <div style={{ flex: 1 }}>
                    <h4>{path.title}</h4>
                    <p className="muted" style={{ fontSize: '13px', marginTop: '4px' }}>
                      {path.reference ?? `Réf. #${path.externalId}`}
                    </p>
                  </div>
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      loadPathDetail(path.id);
                    }}
                    style={{
                      padding: '8px 12px',
                      borderRadius: '8px',
                      border: '1px solid #e5e7eb',
                      background: 'white',
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      gap: '6px',
                      fontSize: '13px',
                      fontWeight: '500',
                      color: '#374151',
                      transition: 'all 0.2s',
                    }}
                    onMouseEnter={(e) => {
                      e.currentTarget.style.background = '#f9fafb';
                      e.currentTarget.style.borderColor = '#d1d5db';
                    }}
                    onMouseLeave={(e) => {
                      e.currentTarget.style.background = 'white';
                      e.currentTarget.style.borderColor = '#e5e7eb';
                    }}
                  >
                    <Eye size={14} />
                    Détails
                  </button>
                </div>
                <div className="path-stats">
                  <div className="path-stat">
                    <div style={{ display: 'flex', alignItems: 'center', gap: '4px', color: '#6b7280', marginBottom: '4px' }}>
                      <Users size={14} />
                      <span style={{ fontSize: '12px' }}>Apprenants</span>
                    </div>
                    <strong>{path.learnerCount}</strong>
                  </div>
                  <div className="path-stat">
                    <div style={{ display: 'flex', alignItems: 'center', gap: '4px', color: '#6b7280', marginBottom: '4px' }}>
                      <BookOpen size={14} />
                      <span style={{ fontSize: '12px' }}>Formations</span>
                    </div>
                    <strong>{path.trainingCount}</strong>
                  </div>
                  <div className="path-stat">
                    <div style={{ display: 'flex', alignItems: 'center', gap: '4px', color: '#6b7280', marginBottom: '4px' }}>
                      <Clock size={14} />
                      <span style={{ fontSize: '12px' }}>Temps total</span>
                    </div>
                    <strong>{formatDuration(path.totalTime)}</strong>
                  </div>
                </div>
                <div className="path-progress" style={{ marginTop: '16px' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <TrendingUp size={14} style={{ color: '#10b981' }} />
                      <span style={{ fontSize: '12px', color: '#6b7280' }}>Progression moyenne</span>
                    </div>
                    <strong style={{ fontSize: '14px', color: path.averageProgress >= 0.8 ? '#10b981' : '#f59e0b' }}>
                      {formatPercentage(path.averageProgress)}
                    </strong>
                  </div>
                  <div className="progress-bar">
                    <div
                      className="progress-fill"
                      style={{
                        width: `${Math.min(path.averageProgress * 100, 100)}%`,
                        background: path.averageProgress >= 0.8
                          ? 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)'
                          : 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)',
                      }}
                    />
                  </div>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}

      {selectedPathDetail && (
        <div
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            background: 'rgba(0, 0, 0, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1000,
            padding: '20px',
          }}
          onClick={closeModal}
        >
          <div
            style={{
              background: 'white',
              borderRadius: '16px',
              maxWidth: '900px',
              width: '100%',
              maxHeight: '90vh',
              overflow: 'auto',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ padding: '20px', borderBottom: '1px solid #e5e7eb' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start' }}>
                <div>
                  <h2 style={{ fontSize: '20px', fontWeight: '600', marginBottom: '4px', color: '#111827' }}>
                    {selectedPathDetail.learningPath.title}
                  </h2>
                  <p className="muted" style={{ fontSize: '13px' }}>
                    {selectedPathDetail.learningPath.reference ?? `Réf. #${selectedPathDetail.learningPath.externalId}`}
                  </p>
                </div>
                <button
                  onClick={closeModal}
                  style={{
                    padding: '6px',
                    borderRadius: '6px',
                    border: 'none',
                    background: '#f3f4f6',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <X size={18} />
                </button>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '12px', marginTop: '16px' }}>
                <div style={{ padding: '10px', borderRadius: '6px', background: '#f9fafb' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '4px' }}>
                    <Users size={14} style={{ color: '#6b7280' }} />
                    <span style={{ fontSize: '11px', color: '#6b7280' }}>Apprenants</span>
                  </div>
                  <strong style={{ fontSize: '16px' }}>{selectedPathDetail.learningPath.learnerCount}</strong>
                </div>
                <div style={{ padding: '10px', borderRadius: '6px', background: '#f9fafb' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '4px' }}>
                    <BookOpen size={14} style={{ color: '#6b7280' }} />
                    <span style={{ fontSize: '11px', color: '#6b7280' }}>Formations</span>
                  </div>
                  <strong style={{ fontSize: '16px' }}>{selectedPathDetail.learningPath.trainingCount}</strong>
                </div>
                <div style={{ padding: '10px', borderRadius: '6px', background: '#f9fafb' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '4px' }}>
                    <Clock size={14} style={{ color: '#6b7280' }} />
                    <span style={{ fontSize: '11px', color: '#6b7280' }}>Temps total</span>
                  </div>
                  <strong style={{ fontSize: '16px' }}>{formatDuration(selectedPathDetail.learningPath.totalTime)}</strong>
                </div>
                <div style={{ padding: '10px', borderRadius: '6px', background: '#f9fafb' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '4px' }}>
                    <TrendingUp size={14} style={{ color: '#6b7280' }} />
                    <span style={{ fontSize: '11px', color: '#6b7280' }}>Progression</span>
                  </div>
                  <strong style={{ fontSize: '16px' }}>{formatPercentage(selectedPathDetail.learningPath.averageProgress)}</strong>
                </div>
              </div>
            </div>

            <div style={{ padding: '20px' }}>
              {detailLoading ? (
                <p className="muted" style={{ textAlign: 'center' }}>Chargement des détails...</p>
              ) : (
                <>
                  <h3 style={{ fontSize: '16px', fontWeight: '600', marginBottom: '12px', color: '#374151' }}>
                    Formations du parcours ({selectedPathDetail.trainings.length})
                  </h3>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                    {selectedPathDetail.trainings.map((training, index) => (
                      <div
                        key={training.id}
                        style={{
                          padding: '12px',
                          borderRadius: '8px',
                          border: '1px solid #e5e7eb',
                          background: 'white',
                        }}
                      >
                        <div style={{ display: 'flex', alignItems: 'start', gap: '10px' }}>
                          <div
                            style={{
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              width: '28px',
                              height: '28px',
                              borderRadius: '6px',
                              background: '#f3f4f6',
                              color: '#374151',
                              fontWeight: '600',
                              fontSize: '13px',
                              flexShrink: 0,
                            }}
                          >
                            {training.position !== null ? training.position + 1 : index + 1}
                          </div>
                          <div style={{ flex: 1 }}>
                            <h4 style={{ fontSize: '14px', fontWeight: '600', marginBottom: '2px', color: '#111827' }}>
                              {training.title}
                            </h4>
                            {training.type && (
                              <p className="muted" style={{ fontSize: '12px', marginBottom: '8px' }}>
                                {training.type}
                              </p>
                            )}
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '12px' }}>
                              <div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '3px', marginBottom: '3px' }}>
                                  <Users size={12} style={{ color: '#9ca3af' }} />
                                  <span style={{ fontSize: '11px', color: '#6b7280' }}>Apprenants</span>
                                </div>
                                <strong style={{ fontSize: '13px' }}>{training.learnerCount}</strong>
                              </div>
                              <div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '3px', marginBottom: '3px' }}>
                                  <Clock size={12} style={{ color: '#9ca3af' }} />
                                  <span style={{ fontSize: '11px', color: '#6b7280' }}>Temps prévu</span>
                                </div>
                                <strong style={{ fontSize: '13px' }}>{training.eduDuration ? formatDuration(training.eduDuration) : '-'}</strong>
                              </div>
                              <div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '3px', marginBottom: '3px' }}>
                                  <TrendingUp size={12} style={{ color: '#9ca3af' }} />
                                  <span style={{ fontSize: '11px', color: '#6b7280' }}>Progression</span>
                                </div>
                                <strong
                                  style={{
                                    fontSize: '13px',
                                    color: training.averageProgress >= 0.8 ? '#10b981' : '#f59e0b',
                                  }}
                                >
                                  {formatPercentage(training.averageProgress)}
                                </strong>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>

                  <div style={{ marginTop: '16px', paddingTop: '16px', borderTop: '1px solid #e5e7eb' }}>
                    <button
                      onClick={() => {
                        closeModal();
                        navigate(`/learningpaths/${selectedPathDetail.learningPath.id}`);
                      }}
                      style={{
                        width: '100%',
                        padding: '10px',
                        borderRadius: '6px',
                        border: 'none',
                        background: '#0063F7',
                        color: 'white',
                        fontSize: '14px',
                        fontWeight: '500',
                        cursor: 'pointer',
                      }}
                    >
                      Voir le détail complet
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
