import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import DOMPurify from 'dompurify';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { SessionsModal } from '../components/SessionsModal';
import { LearnerTable, type LearnerTableData } from '../components/LearnerTable';
import { MemberCompletionStats } from '../components/MemberCompletionStats';
import type { LearningPathDetail } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

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
    <section className="flex flex-col gap-8">
      <div>
        <Button variant="outline" size="sm" onClick={() => navigate('/dashboard')} className="mb-4">
          <ArrowLeft size={15} />
          Retour au tableau de bord
        </Button>
        {data ? (
          <>
            <h2 className="font-display text-3xl font-extrabold tracking-tight">{data.learningPath.title}</h2>
            {data.learningPath.description ? (
              // Rise Up renvoie la description au format HTML riche (paragraphes, sauts de ligne) —
              // rendue via dangerouslySetInnerHTML après nettoyage DOMPurify (jamais de balises brutes
              // affichées à l'apprenant, jamais de script/attribut exécutable injecté).
              <div
                className="mt-1.5 max-w-2xl text-sm leading-relaxed text-muted-foreground [&_p]:mb-2 [&_p:last-child]:mb-0"
                dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(data.learningPath.description) }}
              />
            ) : null}
          </>
        ) : null}
      </div>

      {loading ? <Card><CardContent className="p-5 text-sm text-muted-foreground">Chargement...</CardContent></Card> : null}
      {error ? <Card className="border-destructive/30 bg-destructive/5"><CardContent className="p-5 text-sm text-destructive">{error}</CardContent></Card> : null}

      {data ? (
        <>
          <MemberCompletionStats
            memberLabel="Apprenants inscrits"
            memberCount={data.learningPath.learnerCount}
            averageMasterclassCompletion={data.learningPath.averageMasterclassCompletion}
            averageElearningCompletion={data.learningPath.averageElearningCompletion}
          />

          <LearnerTable
            data={learnerTableData}
            logPaths={[data.learningPath]}
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
