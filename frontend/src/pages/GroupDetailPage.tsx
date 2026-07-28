import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Clock, Users, TrendingUp, BookOpen } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDuration, formatPercentage } from '../lib/format';
import { LearnerTable, type LearnerTableData } from '../components/LearnerTable';
import { SessionsModal } from '../components/SessionsModal';
import type { GroupDetail } from '../types/trackup';

export function GroupDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { token } = useAuth();
  const [data, setData] = useState<GroupDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedMember, setSelectedMember] = useState<{ id: number; name: string } | null>(null);

  useEffect(() => {
    if (!token || !id) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const payload = await apiRequest<GroupDetail>(`/api/groups/${id}`, { token });

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

  const memberTableData: LearnerTableData[] = data?.members.map(member => ({
    id: member.learnerId,
    learnerId: member.learnerId,
    fullName: member.fullName,
    email: member.email,
    totalTime: member.totalTime,
    sessionTime: member.sessionTime,
    elearningTime: member.elearningTime,
    expectedTime: member.expectedTime,
    expectedElearningTime: member.expectedElearningTime,
    joinedAt: member.joinedAt,
  })) || [];

  if (loading) {
    return (
      <div className="page-container">
        <div className="page-loading">Chargement...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="page-container">
        <div className="page-error">{error}</div>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="page-container">
        <div className="page-error">Groupe introuvable.</div>
      </div>
    );
  }

  return (
    <div className="page-container">
      <div className="page-header">
        <button onClick={() => navigate(-1)} className="back-button">
          <ArrowLeft size={20} />
          Retour
        </button>
        <div className="header-content">
          <h1>{data.group.name}</h1>
          {data.group.reference && (
            <p className="muted" style={{ marginTop: '8px' }}>
              {data.group.reference}
            </p>
          )}
        </div>
      </div>

      <div className="learning-path-overview">
        <div className="overview-card">
          <div className="overview-icon" style={{ background: 'linear-gradient(135deg, #5B8DEE 0%, #0063F7 100%)' }}>
            <Users size={24} color="white" />
          </div>
          <div className="overview-content">
            <p className="overview-label">Membres</p>
            <strong className="overview-value">{data.group.memberCount}</strong>
          </div>
        </div>

        <div className="overview-card">
          <div className="overview-icon" style={{ background: 'linear-gradient(135deg, #FF6B9D 0%, #C239B3 100%)' }}>
            <BookOpen size={24} color="white" />
          </div>
          <div className="overview-content">
            <p className="overview-label">Parcours</p>
            <strong className="overview-value">{data.group.learningPathCount}</strong>
          </div>
        </div>

        <div className="overview-card">
          <div className="overview-icon" style={{ background: 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)' }}>
            <Clock size={24} color="white" />
          </div>
          <div className="overview-content">
            <p className="overview-label">Temps total</p>
            <strong className="overview-value">{formatDuration(data.group.totalTime)}</strong>
          </div>
        </div>

        <div className="overview-card">
          <div className="overview-icon" style={{ background: 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)' }}>
            <TrendingUp size={24} color="white" />
          </div>
          <div className="overview-content">
            <p className="overview-label">Progression moyenne</p>
            <strong className="overview-value">{formatPercentage(data.group.averageProgress)}</strong>
          </div>
        </div>
      </div>

      {data.learningPaths.length > 0 && (
        <div className="section-card" style={{ marginBottom: '24px' }}>
          <h3 style={{ marginBottom: '16px' }}>Parcours associés ({data.learningPaths.length})</h3>
          <div className="learning-paths-grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))' }}>
            {data.learningPaths.map((path) => (
              <div
                key={path.id}
                className="learning-path-mini-card"
                onClick={() => navigate(`/learningpaths/${path.id}`)}
                style={{ cursor: 'pointer', padding: '12px', border: '1px solid #e2e8f0', borderRadius: '8px' }}
              >
                {path.imageUrl ? (
                  <img src={path.imageUrl} alt={path.title} style={{ width: '100%', height: '80px', objectFit: 'cover', borderRadius: '4px', marginBottom: '8px' }} />
                ) : (
                  <div style={{ width: '100%', height: '80px', background: '#f1f5f9', borderRadius: '4px', marginBottom: '8px', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    <BookOpen size={24} color="#94a3b8" />
                  </div>
                )}
                <p style={{ fontSize: '13px', fontWeight: 500, margin: 0 }}>{path.title}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      <LearnerTable
        data={memberTableData}
        title="Membres"
        showProgress={false}
        onRowClick={(member) => setSelectedMember({ id: member.learnerId, name: member.fullName })}
      />

      {selectedMember && id ? (
        <SessionsModal
          endpoint={`/api/groups/${id}/members/${selectedMember.id}/sessions`}
          learnerName={selectedMember.name}
          subtitle="Toutes les sessions des parcours du groupe"
          emptyMessage="Aucune session trouvée pour ce membre"
          showLearningPathColumn={true}
          onClose={() => setSelectedMember(null)}
        />
      ) : null}
    </div>
  );
}
