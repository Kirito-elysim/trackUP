import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, BookOpen } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { LearnerTable, type LearnerTableData } from '../components/LearnerTable';
import { MemberCompletionStats } from '../components/MemberCompletionStats';
import { SessionsModal } from '../components/SessionsModal';
import type { GroupDetail } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

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
    return <p className="py-14 text-center text-sm text-muted-foreground">Chargement...</p>;
  }

  if (error) {
    return (
      <Card className="border-destructive/30 bg-destructive/5">
        <CardContent className="p-5 text-sm text-destructive">{error}</CardContent>
      </Card>
    );
  }

  if (!data) {
    return (
      <Card className="border-destructive/30 bg-destructive/5">
        <CardContent className="p-5 text-sm text-destructive">Groupe introuvable.</CardContent>
      </Card>
    );
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="outline" size="sm" onClick={() => navigate(-1)} className="mb-4">
          <ArrowLeft size={15} />
          Retour
        </Button>
        <h2 className="font-display text-3xl font-extrabold tracking-tight">{data.group.name}</h2>
        {data.group.reference && (
          <p className="mt-1.5 text-xs uppercase tracking-wide text-muted-foreground">{data.group.reference}</p>
        )}
      </div>

      <MemberCompletionStats
        memberLabel="Membres"
        memberCount={data.group.memberCount}
        averageMasterclassCompletion={data.group.averageMasterclassCompletion}
        averageElearningCompletion={data.group.averageElearningCompletion}
      />

      {data.learningPaths.length > 0 && (
        <Card>
          <CardContent className="flex flex-col gap-5 p-6">
            <h3 className="font-display text-lg font-bold tracking-tight">Parcours associés ({data.learningPaths.length})</h3>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {data.learningPaths.map((path) => (
                <button
                  key={path.id}
                  onClick={() => navigate(`/learningpaths/${path.id}`)}
                  type="button"
                  className="rounded-md border border-border p-3 text-left transition hover:border-primary/40 hover:bg-muted/40"
                >
                  {path.imageUrl ? (
                    <img src={path.imageUrl} alt={path.title} className="mb-2 h-20 w-full rounded-sm object-cover" />
                  ) : (
                    <div className="mb-2 flex h-20 w-full items-center justify-center rounded-sm bg-muted">
                      <BookOpen size={22} className="text-muted-foreground" />
                    </div>
                  )}
                  <p className="text-sm font-medium leading-tight">{path.title}</p>
                </button>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      <LearnerTable
        data={memberTableData}
        logPaths={data.learningPaths}
        logGroupId={Number(id)}
        logContextLabel={data.group.name}
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
