import { useDeferredValue, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { clampPercentage, formatDuration, formatPercentage } from '../lib/format';
import { Clock, Users, BookOpen, Search, TrendingUp, Eye } from 'lucide-react';
import type { LearningPathSummary, LearningPathDetail } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Progress } from '@/components/ui/progress';
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

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
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Pilotage</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Parcours de formation</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[38ch] text-sm text-muted-foreground">
            Explorez tous les parcours de formation synchronisés depuis Rise Up
          </p>
          <Chip variant="neutral">{learningPaths.length} parcours disponibles</Chip>
        </div>
      </div>

      <Card>
        <CardContent className="p-4">
          <div className="relative">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              type="text"
              placeholder="Rechercher un parcours par titre ou référence..."
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              className="border-0 pl-9 focus-visible:ring-0"
            />
          </div>
        </CardContent>
      </Card>

      {error ? <Card className="border-destructive/30 bg-destructive/5"><CardContent className="p-5 text-sm text-destructive">{error}</CardContent></Card> : null}

      {loading ? (
        <Card><CardContent className="p-12 text-center text-sm text-muted-foreground">Chargement des parcours...</CardContent></Card>
      ) : learningPaths.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center gap-3 p-12 text-center">
            <BookOpen size={36} className="text-muted-foreground opacity-40" />
            <h3 className="font-display text-lg font-bold tracking-tight">Aucun parcours trouvé</h3>
            <p className="text-sm text-muted-foreground">Aucun parcours ne correspond à votre recherche</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {learningPaths.map((path) => (
            <Card
              key={path.id}
              className="cursor-pointer overflow-hidden transition hover:-translate-y-0.5 hover:shadow-lg"
              onClick={() => navigate(`/learningpaths/${path.id}`)}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  navigate(`/learningpaths/${path.id}`);
                }
              }}
            >
              <div className="flex h-36 w-full items-center justify-center bg-muted">
                {path.imageUrl ? (
                  <img src={path.imageUrl} alt={path.title} className="h-full w-full object-cover" />
                ) : (
                  <BookOpen size={32} className="text-muted-foreground" />
                )}
              </div>
              <CardContent className="flex flex-col gap-4 p-5">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h4 className="font-display text-base font-bold leading-tight tracking-tight">{path.title}</h4>
                    <p className="mt-1 text-xs uppercase tracking-wide text-muted-foreground">
                      {path.reference ?? `Réf. #${path.externalId}`}
                    </p>
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={(e) => {
                      e.stopPropagation();
                      void loadPathDetail(path.id);
                    }}
                  >
                    <Eye size={13} />
                    Détails
                  </Button>
                </div>

                <div className="grid grid-cols-3 gap-3 border-y border-border py-3">
                  <StatMini icon={Users} label="Apprenants" value={path.learnerCount} />
                  <StatMini icon={BookOpen} label="Formations" value={path.trainingCount} />
                  <StatMini icon={Clock} label="Temps total" value={formatDuration(path.totalTime)} />
                </div>

                <div className="flex flex-col gap-2">
                  <div className="flex items-center justify-between">
                    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                      <TrendingUp size={12} />
                      Progression moyenne
                    </span>
                    <strong className={`tabular text-sm font-bold ${path.averageProgress >= 80 ? 'text-success' : 'text-primary'}`}>
                      {formatPercentage(path.averageProgress)}
                    </strong>
                  </div>
                  <Progress
                    value={clampPercentage(path.averageProgress)}
                    barClassName={path.averageProgress >= 80 ? 'bg-success' : 'bg-primary'}
                  />
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Dialog open={selectedPathDetail !== null} onOpenChange={(open) => !open && closeModal()}>
        {selectedPathDetail ? (
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{selectedPathDetail.learningPath.title}</DialogTitle>
              <p className="text-sm text-white/50">
                {selectedPathDetail.learningPath.reference ?? `Réf. #${selectedPathDetail.learningPath.externalId}`}
              </p>
              <div className="mt-2 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <MiniStatChip icon={Users} label="Apprenants" value={selectedPathDetail.learningPath.learnerCount} />
                <MiniStatChip icon={BookOpen} label="Formations" value={selectedPathDetail.learningPath.trainingCount} />
                <MiniStatChip icon={Clock} label="Temps total" value={formatDuration(selectedPathDetail.learningPath.totalTime)} />
                <MiniStatChip icon={TrendingUp} label="Progression" value={formatPercentage(selectedPathDetail.learningPath.averageProgress)} />
              </div>
            </DialogHeader>

            <DialogBody>
              {detailLoading ? (
                <p className="py-8 text-center text-sm text-muted-foreground">Chargement des détails...</p>
              ) : (
                <>
                  <h3 className="mb-3 font-display text-sm font-bold uppercase tracking-wide text-muted-foreground">
                    Formations du parcours ({selectedPathDetail.trainings.length})
                  </h3>
                  <div className="flex flex-col gap-2.5">
                    {selectedPathDetail.trainings.map((training, index) => (
                      <div key={training.id} className="rounded-md border border-border p-3.5">
                        <div className="flex items-start gap-3">
                          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-bold">
                            {training.position !== null ? training.position + 1 : index + 1}
                          </span>
                          <div className="min-w-0 flex-1">
                            <h4 className="text-sm font-semibold">{training.title}</h4>
                            {training.type && <p className="mt-0.5 text-xs text-muted-foreground">{training.type}</p>}
                            <div className="mt-2.5 grid grid-cols-3 gap-3">
                              <StatMini icon={Users} label="Apprenants" value={training.learnerCount} small />
                              <StatMini icon={Clock} label="Temps prévu" value={training.eduDuration ? formatDuration(training.eduDuration) : '-'} small />
                              <StatMini
                                icon={TrendingUp}
                                label="Progression"
                                value={formatPercentage(training.averageProgress)}
                                small
                                valueClassName={training.averageProgress >= 80 ? 'text-success' : 'text-primary'}
                              />
                            </div>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>

                  <Button
                    className="mt-5 w-full"
                    onClick={() => {
                      closeModal();
                      navigate(`/learningpaths/${selectedPathDetail.learningPath.id}`);
                    }}
                  >
                    Voir le détail complet
                  </Button>
                </>
              )}
            </DialogBody>
          </DialogContent>
        ) : null}
      </Dialog>
    </section>
  );
}

function StatMini({
  icon: Icon,
  label,
  value,
  small,
  valueClassName,
}: {
  icon: typeof Users;
  label: string;
  value: string | number;
  small?: boolean;
  valueClassName?: string;
}) {
  return (
    <div>
      <div className="mb-1 flex items-center gap-1.5 text-muted-foreground">
        <Icon size={small ? 11 : 12} />
        <span className="text-[0.68rem]">{label}</span>
      </div>
      <strong className={`tabular ${small ? 'text-xs' : 'text-sm'} font-bold ${valueClassName ?? ''}`}>{value}</strong>
    </div>
  );
}

function MiniStatChip({ icon: Icon, label, value }: { icon: typeof Users; label: string; value: string | number }) {
  return (
    <div className="rounded-md border border-white/10 bg-white/[0.04] p-2.5">
      <div className="mb-1 flex items-center gap-1.5 text-white/50">
        <Icon size={12} />
        <span className="text-[0.66rem]">{label}</span>
      </div>
      <strong className="tabular text-sm font-bold">{value}</strong>
    </div>
  );
}
