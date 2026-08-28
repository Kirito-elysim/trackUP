import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { clampPercentage, formatDuration, formatPercentage, formatDateTime } from '../lib/format';
import {
  ArrowLeft,
  Search,
  Clock,
  TrendingUp,
  BookOpen,
  User,
  Calendar,
  CheckCircle,
  XCircle,
  Activity,
  AlertTriangle,
  FilterX,
  Award,
  Target,
  Zap,
  ChevronLeft,
  ChevronRight,
  Building2,
  Eye,
  Loader2,
  Pencil,
  UserRound,
  Phone,
  MapPin,
  MessageSquare,
  Mail,
  RotateCcw,
  ShieldAlert,
  CalendarX2,
} from 'lucide-react';
import type { Company, LearnerCommunicationEntry, LearnerDetail, LearnerSummary, Tutor, TutorsIndexResponse } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Chip } from '@/components/ui/chip';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { CountUp } from '@/components/ui/stat';
import { Avatar } from '@/components/ui/avatar';
import { Select } from '@/components/ui/select';
import { SortableHead, Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { compareValues, type SortDirection } from '../lib/sort';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { SearchSelect } from '@/components/ui/search-select';
import { AbsAvatar, AbsAlertBadge, AbsStatusChip } from '@/components/absences/badges';
import { cn, stateChipVariant } from '@/lib/utils';


type CommunicationDisplay = {
  label: string;
  detail: string | null;
  email: { subject: string; text: string; to: string } | null;
};

const LEARNER_STATE_META: Record<string, { label: string; variant: 'success' | 'destructive' | 'neutral' }> = {
  active: { label: 'Actif', variant: 'success' },
  suspended: { label: 'Suspendu', variant: 'destructive' },
  inactive: { label: 'Inactif', variant: 'neutral' },
};

type DirectorySortKey = 'fullName' | 'trainingCount' | 'averageProgress' | 'totalTime' | 'lastActivityAt';

function learnerStateMeta(state: string): { label: string; variant: 'success' | 'destructive' | 'neutral' } {
  return LEARNER_STATE_META[state.toLowerCase()] ?? { label: state, variant: 'neutral' };
}

function extractCommunicationEmail(meta: Record<string, unknown>): { subject: string; text: string; to: string } | null {
  if (typeof meta.subject !== 'string' || typeof meta.text !== 'string') return null;

  return { subject: meta.subject, text: meta.text, to: typeof meta.to === 'string' ? meta.to : '' };
}

// Traduit un LearnerCommunicationEntry (fusion des emails liés à une absence et des communications
// propres à l'apprenant, ex. email disciplinaire manuel) en rendu affichable pour l'onglet
// "Communications" — mêmes conventions que describeEvent() sur la fiche absence.
function describeCommunication(entry: LearnerCommunicationEntry): CommunicationDisplay {
  const meta = entry.metadata;
  const email = extractCommunicationEmail(meta);

  switch (entry.type) {
    case 'notification_sent': {
      const manual = entry.actorName !== null;
      return {
        label: manual ? `Relance manuelle envoyée par ${entry.actorName}` : 'Email de notification automatique envoyé',
        detail: entry.sessionTitle,
        email,
      };
    }
    case 'confirmation_sent':
      return {
        label: entry.actorName ? `Email de confirmation envoyé par ${entry.actorName}` : 'Email de confirmation automatique envoyé',
        detail: entry.sessionTitle,
        email,
      };
    case 'elearning_reminder': {
      const reason = meta.reason === 'schedule' ? 'Horaires de connexion' : 'Avancement insuffisant';
      return {
        label: entry.actorName ? `Relance e-learning envoyée par ${entry.actorName}` : 'Relance e-learning envoyée',
        detail: reason,
        email,
      };
    }
    case 'disciplinary_email':
    default: {
      const count = typeof meta.consecutiveCount === 'number' ? meta.consecutiveCount : null;
      return {
        label: entry.actorName ? `Email disciplinaire envoyé par ${entry.actorName}` : 'Email disciplinaire envoyé',
        detail: count !== null ? `${count} absences masterclass consécutives` : null,
        email,
      };
    }
  }
}

export function LearnersPage() {
  const { token, canAccess } = useAuth();
  const canManageAssignment = canAccess('companies.view');
  const canManageAbsences = canAccess('absences.manage');
  const navigate = useNavigate();
  const { id: routeLearnerId } = useParams<{ id?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  const deferredQuery = useDeferredValue(searchQuery);
  const [learners, setLearners] = useState<LearnerSummary[]>([]);
  const [stateFilter, setStateFilter] = useState('');
  const [sortKey, setSortKey] = useState<DirectorySortKey>('lastActivityAt');
  const [sortDirection, setSortDirection] = useState<SortDirection>('desc');
  const [selectedLearnerId, setSelectedLearnerId] = useState<number | null>(
    routeLearnerId ? Number(routeLearnerId) : null,
  );
  const [selectedLearner, setSelectedLearner] = useState<LearnerDetail | null>(null);
  const [listLoading, setListLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'formations' | 'sessions' | 'activity' | 'absences' | 'communications'>('formations');
  const [selectedCommunicationEmail, setSelectedCommunicationEmail] = useState<{ subject: string; text: string; to: string } | null>(null);
  const [resettingAbsenceCounter, setResettingAbsenceCounter] = useState(false);
  const [upcomingPage, setUpcomingPage] = useState(1);
  const upcomingPageSize = 4;

  const [now, setNow] = useState(() => Date.now());

  const [tutors, setTutors] = useState<Tutor[]>([]);
  const [companies, setCompanies] = useState<Company[]>([]);
  const [editingAssignment, setEditingAssignment] = useState(false);
  const [assignmentTutorId, setAssignmentTutorId] = useState('');
  const [assignmentCompanyId, setAssignmentCompanyId] = useState('');
  const [assignmentSaving, setAssignmentSaving] = useState(false);
  const [assignmentMessage, setAssignmentMessage] = useState<string | null>(null);

  const [editingProspect, setEditingProspect] = useState(false);
  const [prospectForm, setProspectForm] = useState({
    phoneMobile: '',
    phoneFixe: '',
    address: '',
    postalCode: '',
    city: '',
    dateOfBirth: '',
    comment: '',
  });
  const [prospectSaving, setProspectSaving] = useState(false);
  const [prospectMessage, setProspectMessage] = useState<string | null>(null);

  // Fiche apprenant reprise du prototype /Users/mahdjoub/www/project-2
  // (pages/LearnerPage.tsx) : statistiques et suivi des absences consécutives
  // recalculés depuis la liste d'absences déjà chargée avec le détail apprenant.
  const absenceStats = useMemo(() => {
    const absences = selectedLearner?.absences ?? [];
    return {
      total: absences.length,
      pending: absences.filter((a) => a.status === 'en_attente').length,
      justified: absences.filter((a) => a.status === 'justifiee').length,
      unjustified: absences.filter((a) => a.status === 'non_justifiee').length,
    };
  }, [selectedLearner]);
  const consecutiveCount = selectedLearner?.learner.consecutiveUnjustifiedMasterclassAbsences ?? 0;
  const consecutiveAlertActive = consecutiveCount >= 3;

  const upcomingSessions = useMemo(() => {
    if (!selectedLearner) {
      return [];
    }

    return selectedLearner.sessionRegistrations
      .filter((session) => session.startAt && new Date(session.startAt).getTime() > now)
      .sort((a, b) => new Date(a.startAt!).getTime() - new Date(b.startAt!).getTime());
  }, [selectedLearner, now]);

  const upcomingTotalPages = Math.max(1, Math.ceil(upcomingSessions.length / upcomingPageSize));
  const upcomingPageStartIndex = (upcomingPage - 1) * upcomingPageSize;
  const upcomingPageItems = upcomingSessions.slice(upcomingPageStartIndex, upcomingPageStartIndex + upcomingPageSize);

  const directoryTotals = useMemo(() => {
    const actifs = learners.filter((learner) => learner.state.toLowerCase() === 'active').length;
    const totalTime = learners.reduce((sum, learner) => sum + learner.totalTime, 0);
    const sessions = learners.reduce((sum, learner) => sum + learner.sessionRegistrationCount, 0);
    // Progression pondérée par le nombre de formations suivies par apprenant.
    const registrations = learners.reduce((sum, learner) => sum + learner.trainingCount, 0);
    const weightedProgress =
      registrations > 0 ? learners.reduce((sum, learner) => sum + learner.averageProgress * learner.trainingCount, 0) / registrations : 0;

    return { actifs, totalTime, sessions, weightedProgress };
  }, [learners]);

  const stateOptions = useMemo(() => [...new Set(learners.map((learner) => learner.state))], [learners]);

  const sortedLearners = useMemo(() => {
    const rows = [...learners];
    rows.sort((left, right) => compareValues(left[sortKey], right[sortKey], sortDirection));
    return rows;
  }, [learners, sortDirection, sortKey]);

  const handleSort = (key: DirectorySortKey) => {
    if (sortKey === key) {
      setSortDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
      return;
    }

    setSortKey(key);
    setSortDirection(key === 'fullName' ? 'asc' : 'desc');
  };

  const hasActiveFilters = searchQuery.trim() !== '' || stateFilter !== '';

  const resetFilters = () => {
    setSearchQuery('');
    setStateFilter('');
  };

  const handleBackToDirectory = () => {
    setSelectedLearnerId(null);
    setSelectedLearner(null);
    navigate('/learners');
  };

  useEffect(() => {
    if (routeLearnerId) {
      setSelectedLearnerId(Number(routeLearnerId));
    }
  }, [routeLearnerId]);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const loadLearners = async () => {
      setListLoading(true);
      setError(null);

      const params = new URLSearchParams({ limit: '100' });

      if (deferredQuery.trim() !== '') {
        params.set('q', deferredQuery.trim());
      }

      if (stateFilter !== '') {
        params.set('state', stateFilter);
      }

      try {
        const payload = await apiRequest<LearnerSummary[]>(`/api/learners?${params.toString()}`, { token });

        if (!cancelled) {
          setLearners(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement du répertoire impossible.');
          setLearners([]);
        }
      } finally {
        if (!cancelled) {
          setListLoading(false);
        }
      }
    };

    void loadLearners();

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, stateFilter, token]);

  useEffect(() => {
    if (!token || !selectedLearnerId) {
      return;
    }

    let cancelled = false;

    const loadDetail = async () => {
      setDetailLoading(true);

      try {
        const payload = await apiRequest<LearnerDetail>(`/api/learners/${selectedLearnerId}`, { token });

        if (!cancelled) {
          setSelectedLearner(payload);
          setNow(Date.now());
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Détail apprenant indisponible.');
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
  }, [selectedLearnerId, token]);

  useEffect(() => {
    if (!token || !canManageAssignment) {
      return;
    }

    let cancelled = false;

    const loadAssignmentOptions = async () => {
      try {
        const [tutorsPayload, companiesPayload] = await Promise.all([
          apiRequest<TutorsIndexResponse>('/api/admin/tutors?pageSize=100', { token }),
          apiRequest<{ companies: Company[] }>('/api/admin/companies?pageSize=100', { token }),
        ]);

        if (!cancelled) {
          setTutors(tutorsPayload.tutors);
          setCompanies(companiesPayload.companies);
        }
      } catch {
        // Silencieux : la lecture du tuteur/entreprise déjà rattaché ne dépend pas de ces listes,
        // seule l'édition du rattachement serait indisponible.
      }
    };

    void loadAssignmentOptions();

    return () => {
      cancelled = true;
    };
  }, [token, canManageAssignment]);

  const handleSelectLearner = (learnerId: number) => {
    setSelectedLearnerId(learnerId);
    setUpcomingPage(1);
    setEditingAssignment(false);
    setAssignmentMessage(null);
    setEditingProspect(false);
    setProspectMessage(null);
    navigate(`/learners/${learnerId}`);
  };

  const handleOpenAssignmentEdit = () => {
    if (!selectedLearner) {
      return;
    }

    setAssignmentTutorId(selectedLearner.learner.tutor ? String(selectedLearner.learner.tutor.id) : '');
    setAssignmentCompanyId(selectedLearner.learner.company ? String(selectedLearner.learner.company.id) : '');
    setAssignmentMessage(null);
    setEditingAssignment(true);
  };

  const handleTutorChange = (value: string) => {
    setAssignmentTutorId(value);

    const tutor = tutors.find((candidate) => String(candidate.id) === value);
    setAssignmentCompanyId(tutor && tutor.companies.length === 1 ? String(tutor.companies[0].id) : '');
  };

  const handleSaveAssignment = async () => {
    if (!token || !selectedLearner) {
      return;
    }

    setAssignmentSaving(true);
    setAssignmentMessage(null);

    try {
      const payload = await apiRequest<{ tutor: LearnerDetail['learner']['tutor']; company: LearnerDetail['learner']['company'] }>(
        `/api/learners/${selectedLearner.learner.id}/assignment`,
        {
          method: 'PUT',
          token,
          body: {
            tutorId: assignmentTutorId !== '' ? Number(assignmentTutorId) : null,
            companyId: assignmentCompanyId !== '' ? Number(assignmentCompanyId) : null,
          },
        },
      );

      setSelectedLearner((current) =>
        current ? { ...current, learner: { ...current.learner, tutor: payload.tutor, company: payload.company } } : current,
      );
      setEditingAssignment(false);
    } catch (caught) {
      setAssignmentMessage(caught instanceof ApiError ? caught.message : 'Mise à jour impossible.');
    } finally {
      setAssignmentSaving(false);
    }
  };

  const handleResetAbsenceCounter = async () => {
    if (!token || !selectedLearner) {
      return;
    }

    setResettingAbsenceCounter(true);
    try {
      const payload = await apiRequest<{ consecutiveUnjustifiedMasterclassAbsences: number }>(
        `/api/learners/${selectedLearner.learner.id}/absence-counter/reset`,
        { method: 'POST', token },
      );

      setSelectedLearner((current) =>
        current
          ? {
              ...current,
              learner: {
                ...current.learner,
                consecutiveUnjustifiedMasterclassAbsences: payload.consecutiveUnjustifiedMasterclassAbsences,
                disciplinaryAlertSentAt: null,
              },
            }
          : current,
      );
    } catch {
      // Silencieux : un échec de reset laisse simplement le compteur affiché inchangé.
    } finally {
      setResettingAbsenceCounter(false);
    }
  };

  const handleOpenProspectEdit = () => {
    if (!selectedLearner) {
      return;
    }

    setProspectForm({
      phoneMobile: selectedLearner.learner.prospect?.phoneMobile ?? '',
      phoneFixe: selectedLearner.learner.prospect?.phoneFixe ?? '',
      address: selectedLearner.learner.prospect?.address ?? '',
      postalCode: selectedLearner.learner.prospect?.postalCode ?? '',
      city: selectedLearner.learner.prospect?.city ?? '',
      dateOfBirth: selectedLearner.learner.prospect?.dateOfBirth ?? '',
      comment: selectedLearner.learner.prospect?.comment ?? '',
    });
    setProspectMessage(null);
    setEditingProspect(true);
  };

  const handleSaveProspect = async () => {
    if (!token || !selectedLearner) {
      return;
    }

    setProspectSaving(true);
    setProspectMessage(null);

    try {
      const payload = await apiRequest<{ prospect: LearnerDetail['learner']['prospect'] }>(
        `/api/learners/${selectedLearner.learner.id}/prospect`,
        { method: 'PUT', token, body: prospectForm },
      );

      setSelectedLearner((current) =>
        current ? { ...current, learner: { ...current.learner, prospect: payload.prospect } } : current,
      );
      setEditingProspect(false);
    } catch (caught) {
      setProspectMessage(caught instanceof ApiError ? caught.message : 'Mise à jour impossible.');
    } finally {
      setProspectSaving(false);
    }
  };

  const attendanceRate =
    selectedLearner && selectedLearner.learner.sessionRegistrationCount > 0
      ? (selectedLearner.learner.signedAttendanceCount / selectedLearner.learner.sessionRegistrationCount) * 100
      : 0;
  const completedTrainingsCount = selectedLearner
    ? selectedLearner.trainingRegistrations.filter((t) => (t.progress ?? 0) >= 100).length
    : 0;

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Pilotage</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Apprenants</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[38ch] text-sm text-muted-foreground">
            Répertoire synchronisé depuis Rise Up : activité, progression et assiduité.
          </p>
          <Chip variant="neutral">{learners.length} apprenant{learners.length > 1 ? 's' : ''}</Chip>
        </div>
      </div>

      {error ? (
        <Card className="border-destructive/30 bg-destructive/5">
          <CardContent className="p-5 text-sm text-destructive">{error}</CardContent>
        </Card>
      ) : null}

      {selectedLearnerId === null ? (
        <>
          <Card>
            <CardContent className="grid grid-cols-2 divide-border p-0 sm:grid-cols-4 sm:divide-x">
              <SummaryStat icon={User} label="Apprenants" value={learners.length} hint={`${directoryTotals.actifs} actif${directoryTotals.actifs > 1 ? 's' : ''}`} />
              <SummaryStat icon={TrendingUp} label="Progression moyenne" value={formatPercentage(directoryTotals.weightedProgress)} hint="Pondérée par les inscriptions" />
              <SummaryStat icon={Clock} label="Temps cumulé" value={formatDuration(directoryTotals.totalTime)} hint="Toutes formations" />
              <SummaryStat icon={Calendar} label="Sessions suivies" value={directoryTotals.sessions} hint="Inscriptions aux sessions" />
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
              <div className="relative flex-1">
                <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <Input
                  type="text"
                  placeholder="Rechercher par nom ou email..."
                  value={searchQuery}
                  onChange={(event) => setSearchQuery(event.target.value)}
                  className="border-0 pl-9 focus-visible:ring-0"
                />
              </div>

              <Select value={stateFilter} onChange={(event) => setStateFilter(event.target.value)} aria-label="Filtrer par état" className="lg:w-48">
                <option value="">Tous les états</option>
                {stateOptions.map((option) => (
                  <option key={option} value={option}>
                    {learnerStateMeta(option).label}
                  </option>
                ))}
              </Select>

              {hasActiveFilters ? (
                <Button variant="outline" size="sm" onClick={resetFilters} className="lg:ml-auto">
                  <FilterX size={14} /> Réinitialiser
                </Button>
              ) : null}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-0">
              {listLoading ? (
                <div className="flex flex-col gap-3 p-6">
                  {Array.from({ length: 8 }).map((_, index) => (
                    <Skeleton key={index} className="h-12 w-full" />
                  ))}
                </div>
              ) : sortedLearners.length === 0 ? (
                <div className="flex flex-col items-center gap-3 p-12 text-center">
                  <User size={36} className="text-muted-foreground opacity-40" />
                  <h3 className="font-display text-lg font-bold tracking-tight">Aucun apprenant trouvé</h3>
                  <p className="text-sm text-muted-foreground">Aucun apprenant ne correspond à vos filtres</p>
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
                        <SortableHead active={sortKey === 'fullName'} direction={sortDirection} onClick={() => handleSort('fullName')}>
                          Apprenant
                        </SortableHead>
                        <TableHead>État</TableHead>
                        <SortableHead active={sortKey === 'trainingCount'} direction={sortDirection} onClick={() => handleSort('trainingCount')}>
                          Formations
                        </SortableHead>
                        <TableHead>Sessions</TableHead>
                        <SortableHead active={sortKey === 'averageProgress'} direction={sortDirection} onClick={() => handleSort('averageProgress')} className="min-w-40">
                          Progression
                        </SortableHead>
                        <SortableHead active={sortKey === 'totalTime'} direction={sortDirection} onClick={() => handleSort('totalTime')}>
                          Temps cumulé
                        </SortableHead>
                        <SortableHead active={sortKey === 'lastActivityAt'} direction={sortDirection} onClick={() => handleSort('lastActivityAt')}>
                          Dernière activité
                        </SortableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {sortedLearners.map((learner) => {
                        const stateMeta = learnerStateMeta(learner.state);

                        return (
                          <TableRow
                            key={learner.id}
                            className="cursor-pointer"
                            onClick={() => handleSelectLearner(learner.id)}
                            tabIndex={0}
                            onKeyDown={(event) => {
                              if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                handleSelectLearner(learner.id);
                              }
                            }}
                          >
                            <TableCell>
                              <div className="flex items-center gap-3">
                                <Avatar name={learner.fullName} />
                                <div className="min-w-0">
                                  <p className="truncate text-sm font-semibold">{learner.fullName}</p>
                                  <p className="truncate text-xs text-muted-foreground">{learner.email}</p>
                                </div>
                              </div>
                            </TableCell>
                            <TableCell>
                              <Chip variant={stateMeta.variant} className="capitalize">
                                {stateMeta.label}
                              </Chip>
                            </TableCell>
                            <TableCell className="tabular text-sm font-semibold">{learner.trainingCount}</TableCell>
                            <TableCell className="tabular text-sm">{learner.sessionRegistrationCount}</TableCell>
                            <TableCell>
                              <div className="flex items-center gap-2.5">
                                <Progress value={clampPercentage(learner.averageProgress)} className="w-24" />
                                <span className="tabular text-xs font-semibold">{formatPercentage(learner.averageProgress)}</span>
                              </div>
                            </TableCell>
                            <TableCell className="tabular text-sm">{formatDuration(learner.totalTime)}</TableCell>
                            <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                              {formatDateTime(learner.lastActivityAt ?? learner.lastLoginAt)}
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </TableShell>
              )}
            </CardContent>
          </Card>
        </>
      ) : (
        <div className="flex flex-col gap-6">
          <div>
            <Button variant="outline" size="sm" onClick={handleBackToDirectory}>
              <ArrowLeft size={14} /> Retour au répertoire
            </Button>
          </div>

          {detailLoading && !selectedLearner ? (
            <Card>
              <CardContent className="flex flex-col gap-3 p-6">
                <Skeleton className="h-16 w-full" />
                <Skeleton className="h-40 w-full" />
                <Skeleton className="h-40 w-full" />
              </CardContent>
            </Card>
          ) : null}

          {selectedLearner ? (
        <div className="flex flex-col gap-8">
          <Card className="border-abs-ink-100">
            <CardContent className="flex flex-wrap items-start gap-5 p-6">
              <AbsAvatar name={selectedLearner.learner.fullName} size="lg" className="h-16 w-16 text-lg" />
              <div className="min-w-0 flex-1">
                <h2 className="font-display text-xl font-bold tracking-tight text-abs-ink-900">
                  {selectedLearner.learner.fullName}
                </h2>
                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                  <Chip variant="neutral" className="bg-abs-brand-100 capitalize text-abs-brand-700">
                    {selectedLearner.learner.state}
                  </Chip>
                  {selectedLearner.learner.consecutiveUnjustifiedMasterclassAbsences >= 3 && (
                    <AbsAlertBadge count={selectedLearner.learner.consecutiveUnjustifiedMasterclassAbsences} />
                  )}
                </div>
                <p className="mt-1.5 flex items-center gap-1.5 text-sm text-abs-ink-500">
                  <Mail size={14} /> {selectedLearner.learner.email}
                </p>
              </div>
              <div className="ml-auto flex flex-col gap-2">
                {selectedLearner.learner.lastActivityAt && (
                  <span className="flex items-center gap-1.5 text-xs text-abs-ink-400">
                    <Activity size={13} className="text-abs-brand-600" />
                    Dernière activité : {formatDateTime(selectedLearner.learner.lastActivityAt)}
                  </span>
                )}
                {selectedLearner.learner.activatedAt && (
                  <span className="flex items-center gap-1.5 text-xs text-abs-ink-400">
                    <CheckCircle size={13} className="text-abs-brand-600" />
                    Activé le : {formatDateTime(selectedLearner.learner.activatedAt)}
                  </span>
                )}
              </div>
            </CardContent>
          </Card>

          <Card className="border-abs-ink-100">
            <CardContent className="flex flex-col gap-4 p-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <h3 className="font-display text-lg font-bold tracking-tight text-abs-ink-900">Tuteur &amp; entreprise</h3>
                  <p className="mt-0.5 text-sm text-abs-ink-500">Rattachement pour les envois automatiques mensuels</p>
                </div>
                {canManageAssignment && !editingAssignment ? (
                  <Button variant="outline" size="sm" onClick={handleOpenAssignmentEdit}>
                    <Pencil size={14} />
                    Modifier
                  </Button>
                ) : null}
              </div>

              {editingAssignment ? (
                <div className="flex flex-col gap-4">
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-2">
                      <span className="text-sm font-semibold">Tuteur</span>
                      <SearchSelect
                        options={tutors.map((tutor) => ({
                          value: String(tutor.id),
                          label: tutor.fullName,
                          sublabel: tutor.email ?? undefined,
                        }))}
                        value={assignmentTutorId}
                        onChange={handleTutorChange}
                        placeholder="Choisir un tuteur"
                        allLabel="Aucun tuteur"
                      />
                    </div>
                    <div className="flex flex-col gap-2">
                      <span className="text-sm font-semibold">Entreprise</span>
                      <SearchSelect
                        options={companies.map((company) => ({ value: String(company.id), label: company.name }))}
                        value={assignmentCompanyId}
                        onChange={setAssignmentCompanyId}
                        placeholder="Choisir une entreprise"
                        allLabel="Aucune entreprise"
                      />
                    </div>
                  </div>

                  {assignmentMessage ? <p className="text-sm text-destructive">{assignmentMessage}</p> : null}

                  <div className="flex gap-3">
                    <Button size="sm" disabled={assignmentSaving} onClick={() => void handleSaveAssignment()}>
                      {assignmentSaving ? <Loader2 size={14} className="animate-spin" /> : null}
                      Enregistrer
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => setEditingAssignment(false)}>
                      Annuler
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <AlternanceTile
                    icon={Building2}
                    iconClassName="bg-abs-brand-50 text-abs-brand-600"
                    label="Entreprise"
                    value={selectedLearner.learner.company?.name}
                    empty="Aucune entreprise rattachée."
                    onClick={
                      canManageAssignment && selectedLearner.learner.company
                        ? () => navigate(`/companies/${selectedLearner.learner.company!.id}`)
                        : undefined
                    }
                  />
                  <AlternanceTile
                    icon={UserRound}
                    iconClassName="bg-abs-ink-900 text-white"
                    label="Tuteur"
                    value={selectedLearner.learner.tutor?.fullName}
                    detail={
                      selectedLearner.learner.tutor
                        ? [selectedLearner.learner.tutor.email, selectedLearner.learner.tutor.phoneMobile ?? selectedLearner.learner.tutor.phoneFixe]
                            .filter(Boolean)
                            .join(' · ')
                        : undefined
                    }
                    empty="Aucun tuteur assigné."
                    onClick={
                      canManageAssignment && selectedLearner.learner.tutor
                        ? () => navigate(`/tutors/${selectedLearner.learner.tutor!.id}`)
                        : undefined
                    }
                  />
                </div>
              )}
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div className="grid grid-cols-2 gap-4 lg:col-span-2 lg:grid-cols-4">
              <AbsenceStatTile label="Total absences" value={absenceStats.total} icon={CalendarX2} tone="ink" />
              <AbsenceStatTile label="En attente" value={absenceStats.pending} icon={Clock} tone="warning" />
              <AbsenceStatTile label="Justifiées" value={absenceStats.justified} icon={CheckCircle} tone="success" />
              <AbsenceStatTile
                label="Non justifiées"
                value={absenceStats.unjustified}
                icon={XCircle}
                tone={consecutiveAlertActive ? 'danger' : 'ink'}
                hint={consecutiveAlertActive ? `${consecutiveCount} consécutives` : undefined}
              />
            </div>

            <Card className="border-abs-ink-100">
              <CardContent className="flex flex-col gap-3 p-5">
                <div>
                  <h3 className="font-display text-base font-semibold text-abs-ink-900">Absences consécutives</h3>
                  <p className="mt-0.5 text-sm text-abs-ink-500">Masterclass uniquement</p>
                </div>
                <div className="flex items-end gap-2">
                  <span className="font-display text-4xl font-bold text-abs-ink-900">{consecutiveCount}</span>
                  <span className="mb-1.5 text-sm text-abs-ink-400">/ 3 déclencheur</span>
                </div>
                <div className="flex gap-1.5">
                  {[1, 2, 3].map((n) => (
                    <div
                      key={n}
                      className={cn('h-2 flex-1 rounded-full', consecutiveCount >= n ? 'bg-abs-danger-500' : 'bg-abs-ink-100')}
                    />
                  ))}
                  {consecutiveCount > 3 && <div className="h-2 flex-1 animate-pulse rounded-full bg-abs-danger-700" />}
                </div>
                {consecutiveAlertActive ? (
                  <div className="flex items-start gap-2 rounded-xl border border-abs-danger-200 bg-abs-danger-50 p-3 text-sm text-abs-danger-700">
                    <ShieldAlert size={16} className="mt-0.5 shrink-0" />
                    <span>Seuil d&rsquo;alerte atteint. Procédure disciplinaire déclenchée.</span>
                  </div>
                ) : (
                  <p className="flex items-center gap-1.5 text-xs text-abs-ink-400">
                    <TrendingUp size={13} /> Aucun seuil atteint pour l&rsquo;instant.
                  </p>
                )}
                {canManageAbsences && consecutiveCount > 0 && (
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={resettingAbsenceCounter}
                    onClick={() => void handleResetAbsenceCounter()}
                    className="w-full border-abs-ink-200 text-abs-ink-700 hover:border-abs-brand-300 hover:bg-abs-brand-50"
                  >
                    <RotateCcw size={14} /> Réinitialiser le compteur
                  </Button>
                )}
              </CardContent>
            </Card>
          </div>

          <Card className="border-abs-ink-100">
            <CardContent className="flex flex-col gap-4 p-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="font-display text-lg font-bold tracking-tight text-abs-ink-900">Informations complémentaires</h3>
                {canManageAssignment && !editingProspect ? (
                  <Button variant="outline" size="sm" onClick={handleOpenProspectEdit}>
                    <Pencil size={14} />
                    Modifier
                  </Button>
                ) : null}
              </div>

              {editingProspect ? (
                <div className="flex flex-col gap-4">
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label className="flex flex-col gap-2">
                      <span className="text-sm font-semibold">Téléphone mobile</span>
                      <Input
                        value={prospectForm.phoneMobile}
                        onChange={(event) => setProspectForm((current) => ({ ...current, phoneMobile: event.target.value }))}
                      />
                    </label>
                    <label className="flex flex-col gap-2">
                      <span className="text-sm font-semibold">Téléphone fixe</span>
                      <Input
                        value={prospectForm.phoneFixe}
                        onChange={(event) => setProspectForm((current) => ({ ...current, phoneFixe: event.target.value }))}
                      />
                    </label>
                  </div>

                  <label className="flex flex-col gap-2">
                    <span className="text-sm font-semibold">Adresse</span>
                    <Input
                      value={prospectForm.address}
                      onChange={(event) => setProspectForm((current) => ({ ...current, address: event.target.value }))}
                    />
                  </label>

                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label className="flex flex-col gap-2">
                      <span className="text-sm font-semibold">Code postal</span>
                      <Input
                        value={prospectForm.postalCode}
                        onChange={(event) => setProspectForm((current) => ({ ...current, postalCode: event.target.value }))}
                      />
                    </label>
                    <label className="flex flex-col gap-2">
                      <span className="text-sm font-semibold">Ville</span>
                      <Input
                        value={prospectForm.city}
                        onChange={(event) => setProspectForm((current) => ({ ...current, city: event.target.value }))}
                      />
                    </label>
                  </div>

                  <label className="flex flex-col gap-2">
                    <span className="text-sm font-semibold">Date de naissance</span>
                    <Input
                      type="date"
                      value={prospectForm.dateOfBirth}
                      onChange={(event) => setProspectForm((current) => ({ ...current, dateOfBirth: event.target.value }))}
                      className="max-w-[220px]"
                    />
                  </label>

                  <label className="flex flex-col gap-2">
                    <span className="text-sm font-semibold">Commentaire</span>
                    <Textarea
                      value={prospectForm.comment}
                      onChange={(event) => setProspectForm((current) => ({ ...current, comment: event.target.value }))}
                      rows={3}
                    />
                  </label>

                  {prospectMessage ? <p className="text-sm text-destructive">{prospectMessage}</p> : null}

                  <div className="flex gap-3">
                    <Button size="sm" disabled={prospectSaving} onClick={() => void handleSaveProspect()}>
                      {prospectSaving ? <Loader2 size={14} className="animate-spin" /> : null}
                      Enregistrer
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => setEditingProspect(false)}>
                      Annuler
                    </Button>
                  </div>
                </div>
              ) : (
                <div className="flex flex-col gap-4">
                  <div className="flex flex-wrap gap-6">
                    <div className="flex items-center gap-2.5">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-abs-brand-50 text-abs-brand-600">
                        <Phone size={16} />
                      </span>
                      <div>
                        <p className="text-xs text-abs-ink-400">Téléphone mobile</p>
                        <p className="text-sm font-semibold text-abs-ink-900">{selectedLearner.learner.prospect?.phoneMobile ?? 'Non renseigné'}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2.5">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-abs-brand-50 text-abs-brand-600">
                        <Phone size={16} />
                      </span>
                      <div>
                        <p className="text-xs text-abs-ink-400">Téléphone fixe</p>
                        <p className="text-sm font-semibold text-abs-ink-900">{selectedLearner.learner.prospect?.phoneFixe ?? 'Non renseigné'}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2.5">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-abs-brand-50 text-abs-brand-600">
                        <MapPin size={16} />
                      </span>
                      <div>
                        <p className="text-xs text-abs-ink-400">Adresse</p>
                        <p className="text-sm font-semibold text-abs-ink-900">
                          {selectedLearner.learner.prospect?.address ||
                          selectedLearner.learner.prospect?.postalCode ||
                          selectedLearner.learner.prospect?.city
                            ? [
                                selectedLearner.learner.prospect?.address,
                                [selectedLearner.learner.prospect?.postalCode, selectedLearner.learner.prospect?.city]
                                  .filter(Boolean)
                                  .join(' '),
                              ]
                                .filter(Boolean)
                                .join(', ')
                            : 'Non renseignée'}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2.5">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-abs-brand-50 text-abs-brand-600">
                        <Calendar size={16} />
                      </span>
                      <div>
                        <p className="text-xs text-abs-ink-400">Date de naissance</p>
                        <p className="text-sm font-semibold text-abs-ink-900">{selectedLearner.learner.prospect?.dateOfBirth ?? 'Non renseignée'}</p>
                      </div>
                    </div>
                  </div>
                  {selectedLearner.learner.prospect?.comment ? (
                    <div className="flex items-start gap-2.5 rounded-md border border-abs-ink-100 bg-abs-ink-50 p-3.5 text-sm text-abs-ink-700">
                      <MessageSquare size={15} className="mt-0.5 shrink-0 text-abs-brand-600" />
                      <span>{selectedLearner.learner.prospect.comment}</span>
                    </div>
                  ) : null}
                </div>
              )}
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
              icon={Clock}
              label="Temps du groupe"
              value={formatDuration(selectedLearner.learner.groupTotalTime)}
              progress={Math.min((selectedLearner.learner.groupTotalTime / (100 * 60)) * 100, 100)}
              hint={selectedLearner.learner.groupName || 'Aucun groupe'}
            />
            <KpiCard
              icon={TrendingUp}
              label="Progression moyenne"
              value={formatPercentage(selectedLearner.learner.averageProgress)}
              progress={clampPercentage(selectedLearner.learner.averageProgress)}
              hint={`${selectedLearner.trainingRegistrations.length} formations`}
            />
            <KpiCard
              icon={Target}
              label="Taux d&rsquo;assiduité"
              value={formatPercentage(attendanceRate)}
              progress={attendanceRate}
              hint={`${selectedLearner.learner.signedAttendanceCount} / ${selectedLearner.learner.sessionRegistrationCount} sessions`}
            />
            <KpiCard
              icon={Award}
              label="Formations complètes"
              value={completedTrainingsCount}
              progress={
                selectedLearner.trainingRegistrations.length > 0
                  ? (completedTrainingsCount / selectedLearner.trainingRegistrations.length) * 100
                  : 0
              }
              hint={`${completedTrainingsCount} / ${selectedLearner.trainingRegistrations.length}`}
            />
          </div>

          {upcomingSessions.length > 0 && (
            <Card className="border-abs-ink-100">
              <CardContent className="flex flex-col gap-5 p-6">
                <div className="flex flex-wrap items-center gap-3">
                  <Zap size={17} className="text-abs-brand-600" />
                  <h3 className="font-display text-lg font-bold tracking-tight text-abs-ink-900">Sessions à venir</h3>
                  <Chip variant="neutral">{upcomingSessions.length}</Chip>
                  {upcomingTotalPages > 1 && (
                    <div className="ml-auto flex items-center gap-2">
                      <Button
                        variant="outline"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => setUpcomingPage((page) => Math.max(1, page - 1))}
                        disabled={upcomingPage === 1}
                        aria-label="Page précédente"
                      >
                        <ChevronLeft size={15} />
                      </Button>
                      <span className="tabular w-14 text-center text-sm font-semibold text-abs-ink-400">
                        {upcomingPage} / {upcomingTotalPages}
                      </span>
                      <Button
                        variant="outline"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => setUpcomingPage((page) => Math.min(upcomingTotalPages, page + 1))}
                        disabled={upcomingPage === upcomingTotalPages}
                        aria-label="Page suivante"
                      >
                        <ChevronRight size={15} />
                      </Button>
                    </div>
                  )}
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                  {upcomingPageItems.map((session) => (
                    <div key={session.id} className="rounded-md border border-abs-brand-200 bg-abs-brand-50/60 p-4">
                      <span className="mb-2 inline-flex items-center gap-1.5 rounded-md bg-card px-2.5 py-1 text-xs font-semibold">
                        <Calendar size={13} className="text-abs-brand-600" />
                        {new Date(session.startAt!).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}
                      </span>
                      <h4 className="text-sm font-semibold leading-tight">{session.trainingTitle || 'Session'}</h4>
                      <p className="tabular mt-1.5 text-xs text-abs-ink-400">
                        {new Date(session.startAt!).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}
                        {session.endAt && ` - ${new Date(session.endAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`}
                      </p>
                      <Chip variant="neutral" className="mt-2.5">{session.sessionType || 'Session'}</Chip>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}

          <Card className="border-abs-ink-100">
            <CardContent className="p-6">
              <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as typeof activeTab)}>
                <TabsList className="bg-abs-ink-50">
                  <TabsTrigger value="formations" className="data-[state=active]:bg-abs-brand-100 data-[state=active]:text-abs-brand-700">
                    <BookOpen size={15} />
                    Formations ({selectedLearner.trainingRegistrations.length})
                  </TabsTrigger>
                  <TabsTrigger value="sessions" className="data-[state=active]:bg-abs-brand-100 data-[state=active]:text-abs-brand-700">
                    <Calendar size={15} />
                    Sessions ({selectedLearner.sessionRegistrations.length})
                  </TabsTrigger>
                  <TabsTrigger value="activity" className="data-[state=active]:bg-abs-brand-100 data-[state=active]:text-abs-brand-700">
                    <Activity size={15} />
                    Activité récente ({selectedLearner.recentActivities.length})
                  </TabsTrigger>
                  <TabsTrigger value="absences" className="data-[state=active]:bg-abs-brand-100 data-[state=active]:text-abs-brand-700">
                    <AlertTriangle size={15} />
                    Absences ({selectedLearner.absences.length})
                  </TabsTrigger>
                  <TabsTrigger value="communications" className="data-[state=active]:bg-abs-brand-100 data-[state=active]:text-abs-brand-700">
                    <Mail size={15} />
                    Communications ({selectedLearner.communications.length})
                  </TabsTrigger>
                </TabsList>

                <TabsContent value="formations">
                  <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {selectedLearner.trainingRegistrations.map((registration) => (
                      <div key={registration.id} className="overflow-hidden rounded-xl border border-abs-ink-100">
                        <div className="relative flex h-24 items-center justify-center bg-gradient-to-br from-abs-brand-500 to-abs-brand-700">
                          <BookOpen size={26} className="text-white" />
                          <span className="absolute right-2.5 top-2.5 rounded-md bg-black/40 px-2 py-1 text-xs font-bold text-white">
                            {formatPercentage(registration.progress ?? 0)}
                          </span>
                        </div>
                        <div className="flex flex-col gap-2.5 p-4">
                          <h4 className="text-sm font-semibold leading-tight">{registration.trainingTitle}</h4>
                          <span className="flex items-center gap-1.5 text-xs text-abs-ink-400">
                            <Clock size={12} />
                            {formatDuration(registration.totalTime)}
                          </span>
                          <Chip variant={stateChipVariant(registration.state)} className="w-fit capitalize">
                            {registration.state}
                          </Chip>
                          <div className="flex items-center gap-2.5">
                            <Progress value={registration.progress ?? 0} className="flex-1" barClassName="bg-abs-brand-500" />
                            <span className="tabular text-xs font-semibold text-abs-brand-600">{formatPercentage(registration.progress ?? 0)}</span>
                          </div>
                          {registration.score !== null && (
                            <p className="text-xs text-abs-ink-400">Score: {registration.score}%</p>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </TabsContent>

                <TabsContent value="sessions">
                  <div className="flex flex-col gap-3">
                    {selectedLearner.sessionRegistrations.map((session) => (
                      <div key={session.id} className="rounded-md border border-abs-ink-100 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <h4 className="text-sm font-semibold">{session.trainingTitle || 'Session sans formation'}</h4>
                            <p className="text-xs text-abs-ink-400">{session.sessionType || 'Session'}</p>
                          </div>
                          {session.signedCount > 0 ? (
                            <Chip variant="success">
                              <CheckCircle size={13} />
                              {session.signedCount} signature{session.signedCount > 1 ? 's' : ''}
                            </Chip>
                          ) : (
                            <Chip variant="destructive">
                              <XCircle size={13} />
                              Non signé
                            </Chip>
                          )}
                        </div>
                        <div className="mt-3 flex flex-wrap gap-4 border-t border-abs-ink-100 pt-3">
                          <span className="flex items-center gap-1.5 text-xs text-abs-ink-400">
                            <Calendar size={13} className="text-[#ff6b9d]" />
                            {session.startAt ? formatDateTime(session.startAt) : 'Date non définie'}
                            {session.endAt && ` - ${formatDateTime(session.endAt)}`}
                          </span>
                          {session.eduDuration && (
                            <span className="flex items-center gap-1.5 text-xs text-abs-ink-400">
                              <Clock size={13} className="text-[#ff6b9d]" />
                              Durée: {formatDuration(session.eduDuration)}
                            </span>
                          )}
                          {session.attended !== null && (
                            <Chip variant={session.attended ? 'success' : 'destructive'}>
                              {session.attended ? 'Présent' : 'Absent'}
                            </Chip>
                          )}
                          <Chip variant={stateChipVariant(session.state)} className="capitalize">
                            {session.state}
                          </Chip>
                        </div>
                      </div>
                    ))}
                    {selectedLearner.sessionRegistrations.length === 0 && (
                      <p className="py-8 text-center text-sm text-abs-ink-400">Aucune session enregistrée</p>
                    )}
                  </div>
                </TabsContent>

                <TabsContent value="activity">
                  <div className="flex flex-col gap-3">
                    {selectedLearner.recentActivities.map((activity) => (
                      <div key={activity.id} className="flex gap-3.5 rounded-md border border-abs-ink-100 p-4">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-abs-brand-50 text-abs-brand-600">
                          <Activity size={17} />
                        </span>
                        <div className="min-w-0 flex-1">
                          <h4 className="text-sm font-semibold">{activity.stepTitle}</h4>
                          <p className="mt-0.5 truncate text-xs text-abs-ink-400">
                            {activity.trainingTitle} › {activity.moduleTitle}
                          </p>
                          <div className="mt-2 flex flex-wrap items-center gap-3">
                            <Chip variant="info" className="capitalize">
                              {activity.stepType || 'Step'}
                            </Chip>
                            <span className="tabular text-xs font-semibold text-abs-brand-600">
                              {formatDuration(activity.totalTime ?? activity.timeSpent ?? 0)}
                            </span>
                            <span className="text-xs text-abs-ink-400">{formatDateTime(activity.activityAt || '')}</span>
                          </div>
                          {activity.score !== null && (
                            <p className="mt-1.5 text-xs font-semibold text-success">Score: {activity.score}%</p>
                          )}
                        </div>
                        {(() => {
                          const state = activityStateMeta(activity.state);
                          return <Chip variant={state.variant}>{state.label}</Chip>;
                        })()}
                      </div>
                    ))}
                    {selectedLearner.recentActivities.length === 0 && (
                      <p className="py-8 text-center text-sm text-abs-ink-400">Aucune activité récente</p>
                    )}
                  </div>
                </TabsContent>

                <TabsContent value="absences">
                  <div className="flex flex-col gap-3">
                    {selectedLearner.absences.map((absence) => (
                      <button
                        key={absence.id}
                        type="button"
                        onClick={() => navigate(`/absences/${absence.id}`)}
                        className="w-full rounded-md border border-abs-ink-100 p-4 text-left transition-colors hover:border-abs-brand-200 hover:bg-abs-brand-50/40"
                      >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <h4 className="text-sm font-semibold">{absence.sessionTitle}</h4>
                            <p className="text-xs text-abs-ink-400">
                              {absence.type === 'masterclass' ? 'Masterclass' : 'Session présentiel'}
                            </p>
                          </div>
                          <div className="flex items-center gap-2">
                            <AbsStatusChip status={absence.status} />
                            <ChevronRight size={15} className="text-abs-ink-400" />
                          </div>
                        </div>
                        <div className="mt-3 flex flex-wrap gap-4 border-t border-abs-ink-100 pt-3">
                          <span className="flex items-center gap-1.5 text-xs text-abs-ink-400">
                            <Calendar size={13} className="text-[#ff6b9d]" />
                            {formatDateTime(absence.sessionStartAt)}
                          </span>
                          {absence.justificationSubmittedAt && (
                            <span className="flex items-center gap-1.5 text-xs text-abs-ink-400">
                              <CheckCircle size={13} />
                              Justificatif déposé le {formatDateTime(absence.justificationSubmittedAt)}
                            </span>
                          )}
                          {absence.adminNote && (
                            <span className="text-xs text-abs-ink-400">Note : {absence.adminNote}</span>
                          )}
                        </div>
                      </button>
                    ))}
                    {selectedLearner.absences.length === 0 && (
                      <p className="py-8 text-center text-sm text-abs-ink-400">Aucune absence enregistrée</p>
                    )}
                  </div>
                </TabsContent>

                <TabsContent value="communications">
                  <div className="flex flex-col gap-3">
                    {selectedLearner.communications.map((entry, index) => {
                      const display = describeCommunication(entry);

                      return (
                        <div key={`${entry.type}-${entry.occurredAt}-${index}`} className="rounded-md border border-abs-ink-100 p-4">
                          <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="flex items-start gap-2.5">
                              <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-abs-brand-100 text-abs-brand-600">
                                <Mail size={14} />
                              </span>
                              <div>
                                <p className="text-sm font-semibold">{display.label}</p>
                                {display.detail && <p className="text-xs text-abs-ink-400">{display.detail}</p>}
                              </div>
                            </div>
                            <span className="whitespace-nowrap text-xs text-abs-ink-400">{formatDateTime(entry.occurredAt)}</span>
                          </div>
                          {display.email ? (
                            <button
                              type="button"
                              onClick={() => setSelectedCommunicationEmail(display.email)}
                              className="mt-2 flex items-center gap-1 text-xs font-semibold text-abs-brand-600 hover:text-abs-brand-700"
                            >
                              <Eye size={12} /> Voir le contenu de l&rsquo;email
                            </button>
                          ) : null}
                        </div>
                      );
                    })}
                    {selectedLearner.communications.length === 0 && (
                      <p className="py-8 text-center text-sm text-abs-ink-400">Aucune communication enregistrée</p>
                    )}
                  </div>
                </TabsContent>
              </Tabs>
            </CardContent>
          </Card>
        </div>
          ) : null}
        </div>
      )}

      <Dialog open={selectedCommunicationEmail !== null} onOpenChange={(open) => !open && setSelectedCommunicationEmail(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{selectedCommunicationEmail?.subject}</DialogTitle>
            <DialogDescription>À {selectedCommunicationEmail?.to || 'destinataire inconnu'}</DialogDescription>
          </DialogHeader>
          <DialogBody>
            <p className="whitespace-pre-line text-sm leading-relaxed text-foreground">{selectedCommunicationEmail?.text}</p>
          </DialogBody>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function KpiCard({
  icon: Icon,
  label,
  value,
  progress,
  hint,
}: {
  icon: typeof Clock;
  label: string;
  value: string | number;
  progress: number;
  hint: string;
}) {
  return (
    <Card className="border-abs-ink-100">
      <CardContent className="flex items-start justify-between gap-3 p-5">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-abs-ink-500">{label}</p>
          <CountUp value={value} className="mt-1 text-2xl text-abs-ink-900" />
          <Progress value={progress} className="mt-3" barClassName="bg-abs-brand-500" />
          <span className="mt-1.5 block text-xs text-abs-ink-400">{hint}</span>
        </div>
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-abs-brand-50 text-abs-brand-600 ring-1 ring-abs-brand-100">
          <Icon size={20} strokeWidth={2.2} />
        </span>
      </CardContent>
    </Card>
  );
}

// Fiche apprenant reprise à l'identique de /Users/mahdjoub/www/project-2
// (components/Cards.tsx, StatCard) — carte de statistique compacte utilisée
// pour le récapitulatif des absences en haut de la fiche apprenant.
function AbsenceStatTile({
  label,
  value,
  icon: Icon,
  tone = 'brand',
  hint,
}: {
  label: string;
  value: number;
  icon: typeof Clock;
  tone?: 'brand' | 'success' | 'warning' | 'danger' | 'ink';
  hint?: string;
}) {
  const tones: Record<string, string> = {
    brand: 'bg-abs-brand-50 text-abs-brand-600 ring-abs-brand-100',
    success: 'bg-abs-success-50 text-abs-success-600 ring-abs-success-100',
    warning: 'bg-abs-warning-50 text-abs-warning-600 ring-abs-warning-100',
    danger: 'bg-abs-danger-50 text-abs-danger-600 ring-abs-danger-100',
    ink: 'bg-abs-ink-100 text-abs-ink-600 ring-abs-ink-200',
  };

  return (
    <Card className="border-abs-ink-100">
      <CardContent className="flex items-start justify-between gap-3 p-4">
        <div className="min-w-0">
          <p className="text-xs font-medium text-abs-ink-500">{label}</p>
          <p className="mt-1 font-display text-2xl font-bold tracking-tight text-abs-ink-900">{value}</p>
          {hint && <p className="mt-1 text-[11px] text-abs-ink-400">{hint}</p>}
        </div>
        <span className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-xl ring-1', tones[tone])}>
          <Icon size={17} strokeWidth={2.2} />
        </span>
      </CardContent>
    </Card>
  );
}

// Fiche apprenant reprise à l'identique de /Users/mahdjoub/www/project-2
// (pages/LearnerPage.tsx, AlternanceBlock) — tuile cliquable Entreprise/Tuteur
// avec navigation vers la fiche entreprise ou tuteur quand elle est rattachée.
function AlternanceTile({
  icon: Icon,
  iconClassName,
  label,
  value,
  detail,
  empty,
  onClick,
}: {
  icon: typeof Building2;
  iconClassName: string;
  label: string;
  value?: string | null;
  detail?: string;
  empty: string;
  onClick?: () => void;
}) {
  const hasValue = Boolean(value);

  return (
    <div
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
      onClick={onClick}
      onKeyDown={
        onClick
          ? (event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                onClick();
              }
            }
          : undefined
      }
      className={cn(
        'flex items-start gap-3 rounded-xl border p-4 text-left transition',
        hasValue
          ? cn('border-abs-ink-100', onClick && 'cursor-pointer hover:border-abs-brand-200 hover:bg-abs-brand-50/40')
          : 'border-dashed border-abs-ink-200',
      )}
    >
      <span className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-xl', iconClassName)}>
        <Icon size={20} />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-xs uppercase tracking-wide text-abs-ink-400">{label}</p>
        {hasValue ? (
          <>
            <p className={cn('truncate font-semibold text-abs-ink-900', onClick && 'hover:text-abs-brand-600')}>{value}</p>
            {detail ? <p className="mt-0.5 truncate text-xs text-abs-ink-500">{detail}</p> : null}
          </>
        ) : (
          <p className="mt-1 text-sm text-abs-ink-400">{empty}</p>
        )}
      </div>
    </div>
  );
}

// Statistique de synthèse du répertoire — même présentation que la barre de
// synthèse de la page Formations (icône en dégradé + compteur animé).
function SummaryStat({ icon: Icon, label, value, hint }: { icon: typeof User; label: string; value: string | number; hint: string }) {
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

function activityStateMeta(state: string): { label: string; variant: 'success' | 'accent' | 'neutral' } {
  switch (state.toLowerCase()) {
    case 'completed':
      return { label: 'Terminé', variant: 'success' };
    case 'in_progress':
      return { label: 'En cours', variant: 'accent' };
    case 'not_started':
      return { label: 'Non démarré', variant: 'neutral' };
    default:
      return { label: state, variant: 'neutral' };
  }
}
