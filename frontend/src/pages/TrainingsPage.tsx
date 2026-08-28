import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import DOMPurify from 'dompurify';
import {
  Award,
  BookOpen,
  CalendarDays,
  Clock,
  ExternalLink,
  FilterX,
  Laptop,
  Layers,
  MapPin,
  Search,
  Shuffle,
  TrendingUp,
  Users,
  Video,
  type LucideIcon,
} from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { clampPercentage, formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import { compareValues, type SortDirection } from '../lib/sort';
import type { TrainingDetail, TrainingSummary } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { CountUp } from '@/components/ui/stat';
import { Avatar } from '@/components/ui/avatar';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { SortableHead, Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { PaginationBar } from '@/components/ui/pagination-bar';
import { useClientPagination } from '../lib/useClientPagination';
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

const STATE_LABEL: Record<string, string> = {
  validated: 'Validée',
  draft: 'Brouillon',
};

const STATE_VARIANT: Record<string, 'success' | 'neutral'> = {
  validated: 'success',
  draft: 'neutral',
};

const TYPE_META: Record<string, { label: string; icon: LucideIcon }> = {
  online: { label: 'E-learning', icon: Laptop },
  elearning: { label: 'E-learning', icon: Laptop },
  physical: { label: 'Présentiel', icon: MapPin },
  classroom: { label: 'Présentiel', icon: MapPin },
  blended: { label: 'Mixte', icon: Shuffle },
  mixed: { label: 'Mixte', icon: Shuffle },
};

const SESSION_TYPE_LABEL: Record<string, string> = {
  masterclass: 'Masterclass',
  classroom: 'Classe',
};

type SortKey = 'title' | 'learnersCount' | 'averageProgress' | 'averageScore' | 'totalTime';

function typeMeta(type: string | null | undefined): { label: string; icon: LucideIcon } {
  return TYPE_META[type?.toLowerCase() ?? ''] ?? { label: 'Formation', icon: BookOpen };
}

export function TrainingsPage() {
  const { token } = useAuth();
  const [query, setQuery] = useState('');
  const [state, setState] = useState('');
  const [type, setType] = useState('');
  const [sortKey, setSortKey] = useState<SortKey>('learnersCount');
  const [sortDirection, setSortDirection] = useState<SortDirection>('desc');
  const deferredQuery = useDeferredValue(query);
  const [trainings, setTrainings] = useState<TrainingSummary[]>([]);
  const [selectedTrainingId, setSelectedTrainingId] = useState<number | null>(null);
  const [selectedTraining, setSelectedTraining] = useState<TrainingDetail | null>(null);
  const [listLoading, setListLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const loadTrainings = async () => {
      setListLoading(true);
      setError(null);

      const params = new URLSearchParams({ limit: '100' });

      if (deferredQuery.trim() !== '') {
        params.set('q', deferredQuery.trim());
      }

      if (state !== '') {
        params.set('state', state);
      }

      try {
        const payload = await apiRequest<TrainingSummary[]>(`/api/trainings?${params.toString()}`, { token });

        if (!cancelled) {
          setTrainings(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
          setTrainings([]);
        }
      } finally {
        if (!cancelled) {
          setListLoading(false);
        }
      }
    };

    void loadTrainings();

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, state, token]);

  useEffect(() => {
    if (!token || !selectedTrainingId) {
      return;
    }

    let cancelled = false;

    const loadDetail = async () => {
      setDetailLoading(true);

      try {
        const payload = await apiRequest<TrainingDetail>(`/api/trainings/${selectedTrainingId}`, { token });

        if (!cancelled) {
          setSelectedTraining(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Détail formation indisponible.');
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
  }, [selectedTrainingId, token]);

  const closeDetail = () => {
    setSelectedTrainingId(null);
    setSelectedTraining(null);
  };

  const totals = useMemo(() => {
    const learners = trainings.reduce((sum, training) => sum + training.learnersCount, 0);
    const cancelled = trainings.reduce((sum, training) => sum + training.cancelledCount, 0);
    const totalTime = trainings.reduce((sum, training) => sum + training.totalTime, 0);
    // Progression pondérée par le nombre d'apprenants actifs : une formation suivie
    // par 200 personnes pèse plus qu'une formation à 2 inscrits.
    const weightedProgress =
      learners > 0 ? trainings.reduce((sum, training) => sum + training.averageProgress * training.learnersCount, 0) / learners : 0;
    const validated = trainings.filter((training) => training.state === 'validated').length;

    return { learners, cancelled, totalTime, weightedProgress, validated };
  }, [trainings]);

  const typeOptions = useMemo(
    () => [...new Set(trainings.map((training) => training.type).filter((value): value is string => value !== null && value !== ''))],
    [trainings],
  );

  const visibleTrainings = useMemo(() => {
    const filtered = type === '' ? trainings : trainings.filter((training) => training.type === type);
    const rows = [...filtered];
    rows.sort((left, right) => compareValues(left[sortKey], right[sortKey], sortDirection));
    return rows;
  }, [sortDirection, sortKey, trainings, type]);

  const {
    page: trainingsPage,
    pageSize: trainingsPageSize,
    pagination: trainingsPagination,
    pageRows: paginatedTrainings,
    setPage: setTrainingsPage,
    setPageSize: setTrainingsPageSize,
  } = useClientPagination(visibleTrainings);

  const handleSort = (key: SortKey) => {
    if (sortKey === key) {
      setSortDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
      return;
    }

    setSortKey(key);
    setSortDirection(key === 'title' ? 'asc' : 'desc');
  };

  const hasActiveFilters = query.trim() !== '' || state !== '' || type !== '';

  const resetFilters = () => {
    setQuery('');
    setState('');
    setType('');
  };

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Pilotage</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Formations</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[38ch] text-sm text-muted-foreground">
            Catalogue synchronisé depuis Rise Up : engagement, contenus et sessions.
          </p>
          <Chip variant="neutral">{visibleTrainings.length} formation{visibleTrainings.length > 1 ? 's' : ''}</Chip>
        </div>
      </div>

      <Card>
        <CardContent className="grid grid-cols-2 divide-border p-0 sm:grid-cols-4 sm:divide-x">
          <SummaryStat icon={BookOpen} label="Formations" value={trainings.length} hint={`${totals.validated} validée${totals.validated > 1 ? 's' : ''}`} />
          <SummaryStat icon={Users} label="Apprenants actifs" value={totals.learners} hint={totals.cancelled > 0 ? `${totals.cancelled} annulation${totals.cancelled > 1 ? 's' : ''}` : 'Aucune annulation'} />
          <SummaryStat icon={TrendingUp} label="Progression moyenne" value={formatPercentage(totals.weightedProgress)} hint="Pondérée par les inscrits" />
          <SummaryStat icon={Clock} label="Temps cumulé" value={formatDuration(totals.totalTime)} hint="Toutes formations" />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
          <div className="relative flex-1">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              type="text"
              placeholder="Rechercher par titre ou référence..."
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              className="border-0 pl-9 focus-visible:ring-0"
            />
          </div>

          <div className="grid grid-cols-2 gap-3 lg:w-auto">
            <Select value={state} onChange={(event) => setState(event.target.value)} aria-label="Filtrer par état">
              <option value="">Tous les états</option>
              <option value="validated">Validée</option>
              <option value="draft">Brouillon</option>
            </Select>

            <Select value={type} onChange={(event) => setType(event.target.value)} aria-label="Filtrer par modalité">
              <option value="">Toutes modalités</option>
              {typeOptions.map((option) => (
                <option key={option} value={option}>
                  {typeMeta(option).label}
                </option>
              ))}
            </Select>
          </div>

          {hasActiveFilters ? (
            <Button variant="outline" size="sm" onClick={resetFilters} className="lg:ml-auto">
              <FilterX size={14} /> Réinitialiser
            </Button>
          ) : null}
        </CardContent>
      </Card>

      {error ? (
        <Card className="border-destructive/30 bg-destructive/5">
          <CardContent className="p-5 text-sm text-destructive">{error}</CardContent>
        </Card>
      ) : null}

      <Card className="overflow-hidden">
        <CardContent className="p-0">
          {listLoading ? (
            <div className="flex flex-col gap-3 p-6">
              {Array.from({ length: 6 }).map((_, index) => (
                <Skeleton key={index} className="h-12 w-full" />
              ))}
            </div>
          ) : visibleTrainings.length === 0 ? (
            <div className="flex flex-col items-center gap-3 p-12 text-center">
              <BookOpen size={36} className="text-muted-foreground opacity-40" />
              <h3 className="font-display text-lg font-bold tracking-tight">Aucune formation trouvée</h3>
              <p className="text-sm text-muted-foreground">Aucune formation ne correspond à vos filtres</p>
              {hasActiveFilters ? (
                <Button variant="outline" size="sm" onClick={resetFilters}>
                  <FilterX size={14} /> Réinitialiser les filtres
                </Button>
              ) : null}
            </div>
          ) : (
            <TableShell>
              <Table>
                <TableHeader>
                  <TableRow>
                    <SortableHead active={sortKey === 'title'} direction={sortDirection} onClick={() => handleSort('title')}>
                      Formation
                    </SortableHead>
                    <TableHead>Modalité</TableHead>
                    <TableHead>État</TableHead>
                    <SortableHead active={sortKey === 'learnersCount'} direction={sortDirection} onClick={() => handleSort('learnersCount')}>
                      Apprenants
                    </SortableHead>
                    <SortableHead active={sortKey === 'averageProgress'} direction={sortDirection} onClick={() => handleSort('averageProgress')} className="min-w-40">
                      Progression
                    </SortableHead>
                    <SortableHead active={sortKey === 'averageScore'} direction={sortDirection} onClick={() => handleSort('averageScore')}>
                      Score
                    </SortableHead>
                    <SortableHead active={sortKey === 'totalTime'} direction={sortDirection} onClick={() => handleSort('totalTime')}>
                      Temps cumulé
                    </SortableHead>
                    <TableHead>Contenu</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {paginatedTrainings.map((training) => {
                    const meta = typeMeta(training.type);
                    const TypeIcon = meta.icon;

                    return (
                      <TableRow
                        key={training.id}
                        className="cursor-pointer"
                        onClick={() => setSelectedTrainingId(training.id)}
                        tabIndex={0}
                        onKeyDown={(event) => {
                          if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            setSelectedTrainingId(training.id);
                          }
                        }}
                      >
                        <TableCell className="max-w-72">
                          <p className="truncate text-sm font-semibold">{training.title}</p>
                          <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {training.reference ?? `Réf. #${training.externalId}`}
                            {training.language ? ` · ${training.language.toUpperCase()}` : ''}
                          </p>
                        </TableCell>
                        <TableCell>
                          <span className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            <TypeIcon size={13} className="text-primary" />
                            {meta.label}
                          </span>
                        </TableCell>
                        <TableCell>
                          <Chip variant={training.state ? (STATE_VARIANT[training.state] ?? 'neutral') : 'neutral'}>
                            {training.state ? (STATE_LABEL[training.state] ?? training.state) : 'N/A'}
                          </Chip>
                        </TableCell>
                        <TableCell className="tabular text-sm font-semibold">{training.learnersCount}</TableCell>
                        <TableCell>
                          <div className="flex items-center gap-2.5">
                            <Progress value={clampPercentage(training.averageProgress)} className="w-24" />
                            <span className="tabular text-xs font-semibold">{formatPercentage(training.averageProgress)}</span>
                          </div>
                        </TableCell>
                        <TableCell className="tabular text-sm">
                          {training.averageScore > 0 ? `${Math.round(training.averageScore)}/100` : '—'}
                        </TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(training.totalTime)}</TableCell>
                        <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                          {training.moduleCount} module{training.moduleCount > 1 ? 's' : ''} · {training.sessionCount} session{training.sessionCount > 1 ? 's' : ''}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </TableShell>
          )}

          {!listLoading && visibleTrainings.length > 0 ? (
            <PaginationBar
              pagination={trainingsPagination}
              page={trainingsPage}
              pageSize={trainingsPageSize}
              onPageChange={setTrainingsPage}
              onPageSizeChange={setTrainingsPageSize}
            />
          ) : null}
        </CardContent>
      </Card>

      <Dialog open={selectedTrainingId !== null} onOpenChange={(open) => !open && closeDetail()}>
        <DialogContent className="w-[min(94vw,880px)]">
          {selectedTrainingId !== null ? (
            <>
              <DialogHeader>
                {detailLoading && selectedTraining === null ? (
                  <div className="flex flex-col gap-2">
                    <Skeleton className="h-6 w-2/3" />
                    <Skeleton className="h-4 w-1/3" />
                  </div>
                ) : selectedTraining ? (
                  <TrainingDialogHeader detail={selectedTraining} />
                ) : null}
              </DialogHeader>

              <DialogBody>
                {detailLoading && selectedTraining === null ? (
                  <div className="flex flex-col gap-3 py-4">
                    <Skeleton className="h-20 w-full rounded-xl" />
                    <Skeleton className="h-10 w-72 rounded-full" />
                    <Skeleton className="h-40 w-full rounded-xl" />
                  </div>
                ) : selectedTraining ? (
                  <TrainingDialogBody detail={selectedTraining} loading={detailLoading} />
                ) : null}
              </DialogBody>
            </>
          ) : null}
        </DialogContent>
      </Dialog>
    </section>
  );
}

function SummaryStat({ icon: Icon, label, value, hint }: { icon: LucideIcon; label: string; value: string | number; hint: string }) {
  return (
    <div className="flex items-center gap-3.5 p-5">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-brand text-white">
        <Icon size={18} />
      </span>
      <div className="min-w-0">
        <p className="text-[0.66rem] font-semibold uppercase tracking-[0.1em] text-muted-foreground">{label}</p>
        <CountUp value={value} className="text-xl text-primary" />
        <p className="text-xs text-muted-foreground">{hint}</p>
      </div>
    </div>
  );
}

function TrainingDialogHeader({ detail }: { detail: TrainingDetail }) {
  const { training } = detail;
  const meta = typeMeta(training.type);

  return (
    <>
      <div className="mb-2 flex flex-wrap items-center gap-2 pr-8">
        <Chip variant={training.state ? (STATE_VARIANT[training.state] ?? 'neutral') : 'neutral'}>
          {training.state ? (STATE_LABEL[training.state] ?? training.state) : 'N/A'}
        </Chip>
        <Chip variant="info">{meta.label}</Chip>
        {training.sequential ? <Chip variant="accent">Progression séquentielle</Chip> : null}
        {training.language ? <Chip variant="neutral">{training.language.toUpperCase()}</Chip> : null}
      </div>

      <DialogTitle>{training.title}</DialogTitle>
      <p className="text-sm text-muted-foreground">
        {training.reference ?? `Réf. #${training.externalId}`}
        {training.eduDuration !== null ? ` · Durée prévue ${formatDuration(training.eduDuration)}` : ''}
        {` · Synchronisée le ${formatDateTime(training.syncedAt)}`}
      </p>

      {training.description ? (
        // Rise Up renvoie la description en HTML riche — nettoyée via DOMPurify avant injection.
        <div
          className="mt-2 line-clamp-4 text-sm leading-relaxed text-muted-foreground [&_p]:mb-2 [&_p:last-child]:mb-0 [&_ul]:list-disc [&_ul]:pl-5"
          dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(training.description) }}
        />
      ) : null}

      {training.objective ? (
        <div className="mt-3 rounded-xl border border-border bg-muted/50 p-3.5">
          <p className="mb-1 text-[0.64rem] font-semibold uppercase tracking-wide text-muted-foreground">Objectif pédagogique</p>
          <div
            className="text-sm leading-relaxed [&_p]:mb-2 [&_p:last-child]:mb-0 [&_ul]:list-disc [&_ul]:pl-5"
            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(training.objective) }}
          />
        </div>
      ) : null}

      {training.externalLink ? (
        <Button variant="outline" size="sm" className="mt-3 w-fit" onClick={() => window.open(training.externalLink ?? '', '_blank', 'noopener,noreferrer')}>
          <ExternalLink size={13} /> Ouvrir dans Rise Up
        </Button>
      ) : null}
    </>
  );
}

function TrainingDialogBody({ detail, loading }: { detail: TrainingDetail; loading: boolean }) {
  const { training, modules, sessions, topLearners } = detail;
  // Snapshot figé au montage du détail : sert uniquement à distinguer sessions à venir / passées.
  const [now] = useState(() => Date.now());
  const upcomingSessions = sessions.filter((session) => session.startAt !== null && new Date(session.startAt.replace(' ', 'T')).getTime() > now).length;

  return (
    <div className={cn('flex flex-col gap-6 transition-opacity', loading && 'opacity-60')}>
      <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <MiniStat icon={Users} label="Apprenants actifs" value={training.learnersCount} hint={training.cancelledCount > 0 ? `+${training.cancelledCount} annulée${training.cancelledCount > 1 ? 's' : ''}` : undefined} />
        <MiniStat icon={TrendingUp} label="Progression moyenne" value={formatPercentage(training.averageProgress)} />
        <MiniStat icon={Award} label="Score moyen" value={training.averageScore > 0 ? `${Math.round(training.averageScore)}/100` : '—'} />
        <MiniStat icon={Clock} label="Temps cumulé" value={formatDuration(training.totalTime)} />
      </div>

      <Tabs defaultValue="modules">
        <TabsList>
          <TabsTrigger value="modules">
            <Layers size={14} /> Modules ({modules.length})
          </TabsTrigger>
          <TabsTrigger value="sessions">
            <Video size={14} /> Sessions ({sessions.length})
          </TabsTrigger>
          <TabsTrigger value="learners">
            <Users size={14} /> Top apprenants ({topLearners.length})
          </TabsTrigger>
        </TabsList>

        <TabsContent value="modules">
          {modules.length === 0 ? (
            <EmptyTab message="Aucun module synchronisé pour cette formation." />
          ) : (
            <div className="flex flex-col gap-2.5">
              {modules.map((module, index) => {
                const meta = typeMeta(module.type);
                const ModuleIcon = meta.icon;

                return (
                  <div key={module.id} className="flex items-center gap-3 rounded-xl border border-border p-3.5">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-xs font-bold">
                      {module.position !== null ? module.position + 1 : index + 1}
                    </span>
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                      <ModuleIcon size={14} />
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold">{module.title}</p>
                      <p className="text-xs text-muted-foreground">
                        {meta.label} · {module.stepCount} étape{module.stepCount > 1 ? 's' : ''}
                      </p>
                    </div>
                    <span className="tabular shrink-0 text-sm font-semibold">
                      {formatDuration(module.eduDuration ?? module.duration ?? 0)}
                    </span>
                  </div>
                );
              })}
            </div>
          )}
        </TabsContent>

        <TabsContent value="sessions">
          {sessions.length === 0 ? (
            <EmptyTab message="Aucune session planifiée pour cette formation." />
          ) : (
            <div className="flex flex-col gap-4">
              {upcomingSessions > 0 ? (
                <Chip variant="info" className="w-fit">
                  <CalendarDays size={12} /> {upcomingSessions} session{upcomingSessions > 1 ? 's' : ''} à venir
                </Chip>
              ) : null}

              <div className="flex flex-col gap-2.5">
                {sessions.map((session) => {
                  const start = session.startAt !== null ? new Date(session.startAt.replace(' ', 'T')).getTime() : null;
                  const isUpcoming = start !== null && start > now;
                  const attendanceRate = session.registrationCount > 0 ? (session.attendedCount / session.registrationCount) * 100 : 0;
                  const occupancy = session.seats !== null && session.seats > 0 ? (session.registrationCount / session.seats) * 100 : null;

                  return (
                    <div key={session.id} className="flex flex-col gap-3 rounded-xl border border-border p-3.5">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <p className="text-sm font-semibold">
                            {SESSION_TYPE_LABEL[session.sessionType ?? ''] ?? 'Session'} · {formatDateTime(session.startAt)}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            {formatDuration(session.eduDuration ?? 0)}
                            {session.location ? ` · ${session.location}` : ''}
                            {session.room ? ` · Salle ${session.room}` : ''}
                            {session.meetingUrl ? ' · Distanciel' : ''}
                          </p>
                        </div>
                        <Chip variant={isUpcoming ? 'info' : 'neutral'}>{isUpcoming ? 'À venir' : 'Terminée'}</Chip>
                      </div>

                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="flex items-center gap-3">
                          <Progress value={clampPercentage(attendanceRate)} className="flex-1" />
                          <span className="tabular shrink-0 text-xs font-semibold">
                            {session.attendedCount}/{session.registrationCount} émargés
                          </span>
                        </div>
                        {occupancy !== null ? (
                          <div className="flex items-center gap-3">
                            <Progress value={clampPercentage(occupancy)} className="flex-1" barClassName="bg-accent" />
                            <span className="tabular shrink-0 text-xs font-semibold">
                              {session.registrationCount}/{session.seats} places
                            </span>
                          </div>
                        ) : null}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </TabsContent>

        <TabsContent value="learners">
          {topLearners.length === 0 ? (
            <EmptyTab message="Aucune inscription active pour cette formation." />
          ) : (
            <div className="flex flex-col gap-2.5">
              {topLearners.map((learner, index) => (
                <div key={learner.id} className="flex items-center gap-3 rounded-xl border border-border p-3.5">
                  <span className="w-5 shrink-0 text-center text-xs font-bold text-muted-foreground">{index + 1}</span>
                  <Avatar name={learner.fullName} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{learner.fullName}</p>
                    <p className="truncate text-xs text-muted-foreground">{learner.email}</p>
                  </div>
                  <div className="hidden w-32 shrink-0 sm:block">
                    <Progress value={clampPercentage(learner.progress)} />
                  </div>
                  <div className="shrink-0 text-right">
                    <p className="tabular text-sm font-semibold">{formatDuration(learner.totalTime)}</p>
                    <p className="tabular text-xs text-muted-foreground">
                      {formatPercentage(learner.progress)}
                      {learner.score !== null ? ` · ${Math.round(learner.score)}/100` : ''}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}

function MiniStat({ icon: Icon, label, value, hint }: { icon: LucideIcon; label: string; value: string | number; hint?: string }) {
  return (
    <div className="rounded-xl border border-border bg-muted/50 p-3">
      <div className="mb-1 flex items-center gap-1.5 text-muted-foreground">
        <Icon size={12} />
        <span className="text-[0.66rem] font-semibold uppercase tracking-wide">{label}</span>
      </div>
      <strong className="tabular text-sm font-bold text-primary">{value}</strong>
      {hint ? <p className="mt-0.5 text-[0.68rem] text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

function EmptyTab({ message }: { message: string }) {
  return (
    <div className="rounded-xl border border-dashed border-border p-10 text-center">
      <p className="text-sm text-muted-foreground">{message}</p>
    </div>
  );
}
