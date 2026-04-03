import { useEffect, useState } from 'react';
import { X, Check, XCircle, Clock, Calendar } from 'lucide-react';
import { apiRequest, ApiError } from '../lib/api';
import { useAuth } from '../contexts/useAuth';
import { formatDateTime, formatDuration } from '../lib/format';
import type { LearnerSessionsPayload } from '../types/trackup';

type Props = {
  learningPathId: number;
  learnerId: number;
  learnerName: string;
  onClose: () => void;
};

export function LearnerSessionsModal({ learningPathId, learnerId, learnerName, onClose }: Props) {
  const { token } = useAuth();
  const [data, setData] = useState<LearnerSessionsPayload | null>(null);
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
        const payload = await apiRequest<LearnerSessionsPayload>(
          `/api/learningpaths/${learningPathId}/learners/${learnerId}/sessions`,
          { token }
        );

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
  }, [token, learningPathId, learnerId]);

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <div>
            <h3>Sessions de {learnerName}</h3>
            <p className="modal-subtitle">Détail des sessions pour ce parcours</p>
          </div>
          <button className="modal-close" onClick={onClose} type="button">
            <X size={24} />
          </button>
        </div>

        <div className="modal-body">
          {loading ? <div className="loading-state">Chargement des sessions...</div> : null}
          {error ? <div className="error-state">{error}</div> : null}

          {data && data.sessions.length === 0 ? (
            <div className="empty-state">
              <Calendar size={48} />
              <p>Aucune session trouvée pour cet apprenant</p>
            </div>
          ) : null}

          {data && data.sessions.length > 0 ? (
            <>
              {data.sessions.filter((s) => s.isFuture).length > 0 ? (
                <div className="sessions-section">
                  <h4 className="sessions-section-title upcoming-title">
                    <Calendar size={20} />
                    Sessions à venir ({data.sessions.filter((s) => s.isFuture).length})
                  </h4>
                  <div className="sessions-table-wrapper">
                    <table className="sessions-table">
                      <thead>
                        <tr>
                          <th>Session</th>
                          <th>Formation</th>
                          <th>Dates</th>
                          <th>Durée prévue</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.sessions
                          .filter((s) => s.isFuture)
                          .map((session) => (
                            <tr key={session.id} className="upcoming-session-row">
                              <td>
                                <div className="session-info">
                                  <strong>{session.title || 'Session sans titre'}</strong>
                                  <span className="session-type-badge upcoming-badge">À venir</span>
                                </div>
                              </td>
                              <td>
                                <span className="training-title">{session.trainingTitle}</span>
                              </td>
                              <td>
                                <div className="session-dates">
                                  {session.startAt ? (
                                    <>
                                      <div className="date-row">
                                        <Clock size={14} />
                                        <span>{formatDateTime(session.startAt)}</span>
                                      </div>
                                      {session.endAt ? (
                                        <div className="date-row">
                                          <Clock size={14} />
                                          <span>{formatDateTime(session.endAt)}</span>
                                        </div>
                                      ) : null}
                                    </>
                                  ) : (
                                    <span className="muted-text">Non planifiée</span>
                                  )}
                                </div>
                              </td>
                              <td>
                                <strong className="duration-value">
                                  {session.eduDuration ? formatDuration(session.eduDuration) : 'N/A'}
                                </strong>
                              </td>
                            </tr>
                          ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              ) : null}

              {data.sessions.filter((s) => !s.isFuture).length > 0 ? (
                <div className="sessions-section">
                  <h4 className="sessions-section-title past-title">
                    <Clock size={20} />
                    Sessions passées ({data.sessions.filter((s) => !s.isFuture).length})
                  </h4>
                  <div className="sessions-table-wrapper">
                    <table className="sessions-table">
                      <thead>
                        <tr>
                          <th>Session</th>
                          <th>Formation</th>
                          <th>Dates</th>
                          <th>Durée</th>
                          <th>Temps compté</th>
                          <th>Signature</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.sessions
                          .filter((s) => !s.isFuture)
                          .map((session) => (
                            <tr key={session.id}>
                              <td>
                                <div className="session-info">
                                  <strong>{session.title || 'Session sans titre'}</strong>
                                  <span className="session-type-badge">{session.sessionType}</span>
                                </div>
                              </td>
                              <td>
                                <span className="training-title">{session.trainingTitle}</span>
                              </td>
                              <td>
                                <div className="session-dates">
                                  {session.startAt ? (
                                    <>
                                      <div className="date-row">
                                        <Clock size={14} />
                                        <span>{formatDateTime(session.startAt)}</span>
                                      </div>
                                      {session.endAt ? (
                                        <div className="date-row">
                                          <Clock size={14} />
                                          <span>{formatDateTime(session.endAt)}</span>
                                        </div>
                                      ) : null}
                                    </>
                                  ) : (
                                    <span className="muted-text">Non planifiée</span>
                                  )}
                                </div>
                              </td>
                              <td>
                                <strong className="duration-value">
                                  {session.eduDuration ? formatDuration(session.eduDuration) : 'N/A'}
                                </strong>
                              </td>
                              <td>
                                <strong
                                  className="counted-time-value"
                                  style={{ color: session.countedTime > 0 ? 'var(--brand)' : 'var(--text-soft)' }}
                                >
                                  {formatDuration(session.countedTime)}
                                </strong>
                              </td>
                              <td>
                                <div className="signature-cell">
                                  {session.hasSigned ? (
                                    <>
                                      <Check size={18} color="#00A67E" />
                                      <span className="signed-text">Signé</span>
                                      {session.signatureDate ? (
                                        <small className="signature-date">{formatDateTime(session.signatureDate)}</small>
                                      ) : null}
                                    </>
                                  ) : (
                                    <>
                                      <XCircle size={18} color="#E53E3E" />
                                      <span className="not-signed-text">Non signé</span>
                                    </>
                                  )}
                                </div>
                              </td>
                            </tr>
                          ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              ) : null}
            </>
          ) : null}
        </div>
      </div>
    </div>
  );
}
