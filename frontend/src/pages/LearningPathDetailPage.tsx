import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Clock, Users, TrendingUp } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDuration, formatPercentage } from '../lib/format';
import { SessionsModal } from '../components/SessionsModal';
import { LearnerTable, type LearnerTableData } from '../components/LearnerTable';
import type { LearningPathDetail } from '../types/trackup';

export function LearningPathDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { token } = useAuth();
  const [data, setData] = useState<LearningPathDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedLearner, setSelectedLearner] = useState<{ id: number; name: string } | null>(null);

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

  const learnerTableData: LearnerTableData[] = data?.learners.map(learner => ({
    id: learner.id,
    learnerId: learner.learnerId,
    fullName: learner.fullName,
    email: learner.email,
    totalTime: learner.sessionTime + learner.elearningTime,
    sessionTime: learner.sessionTime,
    elearningTime: learner.elearningTime,
    expectedTime: learner.expectedTime,
    expectedElearningTime: learner.expectedElearningTime,
    averageProgress: learner.averageProgress,
    subscribedAt: learner.subscribedAt,
  })) || [];

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

          <LearnerTable
            data={learnerTableData}
            title="Apprenants"
            showProgress={true}
            onRowClick={(learner) => setSelectedLearner({ id: learner.learnerId, name: learner.fullName })}
          />
        </>
      ) : null}

      {selectedLearner && id ? (
        <SessionsModal
          endpoint={`/api/learningpaths/${id}/learners/${selectedLearner.id}/sessions`}
          learnerName={selectedLearner.name}
          subtitle="Détail des sessions pour ce parcours"
          emptyMessage="Aucune session trouvée pour cet apprenant"
          showLearningPathColumn={false}
          onClose={() => setSelectedLearner(null)}
        />
      ) : null}
    </section>
  );
}
