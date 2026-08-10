import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Clock, Users, TrendingUp } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDuration, formatPercentage } from '../lib/format';
import { SessionsModal } from '../components/SessionsModal';
import { LearnerTable, type LearnerTableData } from '../components/LearnerTable';
import type { LearningPathDetail } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { CountUp } from '@/components/ui/stat';

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
            {data.learningPath.description ? <p className="mt-1.5 text-sm text-muted-foreground">{data.learningPath.description}</p> : null}
          </>
        ) : null}
      </div>

      {loading ? <Card><CardContent className="p-5 text-sm text-muted-foreground">Chargement...</CardContent></Card> : null}
      {error ? <Card className="border-destructive/30 bg-destructive/5"><CardContent className="p-5 text-sm text-destructive">{error}</CardContent></Card> : null}

      {data ? (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <OverviewStat icon={Users} label="Apprenants inscrits" value={data.learningPath.learnerCount} delay={0} />
            <OverviewStat icon={Clock} label="Temps total" value={formatDuration(data.learningPath.totalTime)} delay={80} />
            <OverviewStat icon={TrendingUp} label="Progression moyenne" value={formatPercentage(data.learningPath.averageProgress)} delay={160} />
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

function OverviewStat({
  icon: Icon,
  label,
  value,
  delay,
}: {
  icon: typeof Users;
  label: string;
  value: string | number;
  delay: number;
}) {
  return (
    <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: `${delay}ms` }}>
      <CardContent className="flex items-center gap-4 p-5">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-brand text-white">
          <Icon size={20} />
        </span>
        <div>
          <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p>
          <CountUp value={value} className="text-xl" />
        </div>
      </CardContent>
    </Card>
  );
}
