import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { clampPercentage, formatDuration, formatPercentage, formatDateTime } from '../lib/format';
import {
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
  X,
  Award,
  Target,
  Zap,
  ChevronLeft,
  ChevronRight,
  Building2,
  Loader2,
  Pencil,
  UserRound,
  Phone,
  MapPin,
  MessageSquare,
} from 'lucide-react';
import type { Company, LearnerDetail, LearnerSummary, Tutor, TutorsIndexResponse } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Chip } from '@/components/ui/chip';
import { Progress } from '@/components/ui/progress';
import { Avatar } from '@/components/ui/avatar';
import { CountUp } from '@/components/ui/stat';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { SearchSelect } from '@/components/ui/search-select';
import { cn, learnerStateChipClass, stateChipVariant } from '@/lib/utils';

const ABSENCE_STATUS_LABEL: Record<LearnerDetail['absences'][number]['status'], string> = {
  en_attente: 'En attente',
  justifiee: 'Justifiée',
  non_justifiee: 'Non justifiée',
  autre: 'Autre',
};

const ABSENCE_STATUS_VARIANT: Record<LearnerDetail['absences'][number]['status'], 'neutral' | 'success' | 'destructive' | 'info'> = {
  en_attente: 'neutral',
  justifiee: 'success',
  non_justifiee: 'destructive',
  autre: 'info',
};

export function LearnersPage() {
  const { token, canAccess } = useAuth();
  const canManageAssignment = canAccess('companies.view');
  const canManageAbsences = canAccess('absences.manage');
  const navigate = useNavigate();
  const { id: routeLearnerId } = useParams<{ id?: string }>();
  const [searchQuery, setSearchQuery] = useState('');
  const deferredQuery = useDeferredValue(searchQuery);
  const [learners, setLearners] = useState<LearnerSummary[]>([]);
  const [selectedLearnerId, setSelectedLearnerId] = useState<number | null>(
    routeLearnerId ? Number(routeLearnerId) : null,
  );
  const [selectedLearner, setSelectedLearner] = useState<LearnerDetail | null>(null);
  const [showSearchResults, setShowSearchResults] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'formations' | 'sessions' | 'activity' | 'absences'>('formations');
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

  const visibleLearners = deferredQuery.trim().length < 2 ? [] : learners;

  useEffect(() => {
    if (routeLearnerId) {
      setSelectedLearnerId(Number(routeLearnerId));
    }
  }, [routeLearnerId]);

  useEffect(() => {
    if (!token || deferredQuery.trim().length < 2) {
      return;
    }

    let cancelled = false;

    const loadLearners = async () => {
      setError(null);

      const params = new URLSearchParams({
        limit: '20',
        q: deferredQuery.trim()
      });

      try {
        const payload = await apiRequest<LearnerSummary[]>(`/api/learners?${params.toString()}`, { token });

        if (!cancelled) {
          setLearners(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Recherche impossible.');
          setLearners([]);
        }
      }
    };

    void loadLearners();

    return () => {
      cancelled = true;
    };
  }, [deferredQuery, token]);

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
    setShowSearchResults(false);
    setSearchQuery('');
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
      <div>
        <h2 className="font-display text-3xl font-extrabold tracking-tight">Apprenants</h2>
        <p className="mt-1.5 text-sm text-muted-foreground">Recherchez et consultez les détails de vos apprenants</p>
      </div>

      <div className="relative mx-auto w-full max-w-2xl">
        <Card className="p-1">
          <div className="relative flex items-center">
            <Search size={18} className="pointer-events-none absolute left-4 text-primary" />
            <input
              type="text"
              placeholder="Rechercher un apprenant par nom ou email..."
              value={searchQuery}
              onChange={(e) => {
                setSearchQuery(e.target.value);
                setShowSearchResults(e.target.value.length >= 2);
              }}
              onFocus={() => setShowSearchResults(searchQuery.length >= 2)}
              className="h-12 w-full rounded-md border-0 bg-transparent pl-11 pr-11 text-sm focus:outline-none"
            />
            {searchQuery.length > 0 && (
              <button
                onClick={() => {
                  setSearchQuery('');
                  setShowSearchResults(false);
                }}
                type="button"
                className="absolute right-3 flex h-7 w-7 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              >
                <X size={15} />
              </button>
            )}
          </div>
        </Card>

        {showSearchResults && (
          <div className="absolute top-full z-20 mt-2 w-full overflow-hidden rounded-md border border-border bg-card shadow-lg">
            {visibleLearners.length > 0 ? (
              <div className="max-h-96 overflow-y-auto p-2">
                {visibleLearners.map((learner) => (
                  <button
                    key={learner.id}
                    onClick={() => handleSelectLearner(learner.id)}
                    type="button"
                    className="flex w-full items-center gap-3 rounded-md p-3 text-left transition hover:bg-muted"
                  >
                    <Avatar name={learner.fullName} className="h-10 w-10 text-sm" />
                    <div className="min-w-0 flex-1">
                      <strong className="block truncate text-sm font-semibold">{learner.fullName}</strong>
                      <span className="block truncate text-xs text-muted-foreground">{learner.email}</span>
                    </div>
                    <Chip variant="neutral" className={cn('capitalize', learnerStateChipClass(learner.state))}>
                      {learner.state}
                    </Chip>
                  </button>
                ))}
              </div>
            ) : (
              <div className="flex flex-col items-center gap-3 p-10 text-center text-muted-foreground">
                <User size={26} className="opacity-40" />
                <p className="text-sm">{searchQuery.length < 2 ? 'Tapez au moins 2 caractères pour rechercher' : 'Aucun apprenant trouvé'}</p>
              </div>
            )}
          </div>
        )}
      </div>

      {error && (
        <Card className="border-destructive/30 bg-destructive/5">
          <CardContent className="p-5 text-sm text-destructive">{error}</CardContent>
        </Card>
      )}

      {!selectedLearner && !detailLoading && (
        <Card className="border-dashed">
          <CardContent className="flex flex-col items-center gap-3 p-16 text-center">
            <span className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-primary">
              <User size={30} />
            </span>
            <h3 className="font-display text-lg font-bold tracking-tight">Sélectionnez un apprenant</h3>
            <p className="max-w-sm text-sm text-muted-foreground">
              Utilisez la barre de recherche ci-dessus pour trouver et afficher les détails d&rsquo;un apprenant
            </p>
          </CardContent>
        </Card>
      )}

      {detailLoading && <p className="py-12 text-center text-sm text-muted-foreground">Chargement des détails...</p>}

      {selectedLearner && (
        <div className="flex flex-col gap-8">
          <Card>
            <CardContent className="flex flex-wrap items-start gap-5 p-6">
              <Avatar name={selectedLearner.learner.fullName} className="h-16 w-16 text-lg" />
              <div className="min-w-0 flex-1">
                <h2 className="font-display text-xl font-bold tracking-tight">{selectedLearner.learner.fullName}</h2>
                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                  <Chip variant="primary">{selectedLearner.learner.state}</Chip>
                  {selectedLearner.learner.consecutiveUnjustifiedMasterclassAbsences >= 3 && (
                    <Badge variant="destructive" className="gap-1">
                      <AlertTriangle size={12} />
                      {selectedLearner.learner.consecutiveUnjustifiedMasterclassAbsences} absences masterclass consécutives non justifiées
                    </Badge>
                  )}
                  {canManageAbsences && selectedLearner.learner.consecutiveUnjustifiedMasterclassAbsences > 0 && (
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={resettingAbsenceCounter}
                      onClick={() => void handleResetAbsenceCounter()}
                    >
                      Réinitialiser le compteur
                    </Button>
                  )}
                </div>
                <p className="mt-1.5 text-sm text-muted-foreground">{selectedLearner.learner.email}</p>
              </div>
              <div className="ml-auto flex flex-col gap-2">
                {selectedLearner.learner.lastActivityAt && (
                  <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Activity size={13} className="text-primary" />
                    Dernière activité : {formatDateTime(selectedLearner.learner.lastActivityAt)}
                  </span>
                )}
                {selectedLearner.learner.activatedAt && (
                  <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <CheckCircle size={13} className="text-primary" />
                    Activé le : {formatDateTime(selectedLearner.learner.activatedAt)}
                  </span>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-4 p-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="font-display text-lg font-bold tracking-tight">Tuteur &amp; entreprise</h3>
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
                <div className="flex flex-wrap gap-6">
                  <div className="flex items-center gap-2.5">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                      <UserRound size={16} />
                    </span>
                    <div>
                      <p className="text-xs text-muted-foreground">Tuteur</p>
                      <p className="text-sm font-semibold">{selectedLearner.learner.tutor?.fullName ?? 'Non rattaché'}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2.5">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                      <Building2 size={16} />
                    </span>
                    <div>
                      <p className="text-xs text-muted-foreground">Entreprise</p>
                      <p className="text-sm font-semibold">{selectedLearner.learner.company?.name ?? 'Non rattachée'}</p>
                    </div>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-4 p-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="font-display text-lg font-bold tracking-tight">Informations complémentaires</h3>
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
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Phone size={16} />
                      </span>
                      <div>
                        <p className="text-xs text-muted-foreground">Téléphone mobile</p>
                        <p className="text-sm font-semibold">{selectedLearner.learner.prospect?.phoneMobile ?? 'Non renseigné'}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2.5">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Phone size={16} />
                      </span>
                      <div>
                        <p className="text-xs text-muted-foreground">Téléphone fixe</p>
                        <p className="text-sm font-semibold">{selectedLearner.learner.prospect?.phoneFixe ?? 'Non renseigné'}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2.5">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <MapPin size={16} />
                      </span>
                      <div>
                        <p className="text-xs text-muted-foreground">Adresse</p>
                        <p className="text-sm font-semibold">
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
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Calendar size={16} />
                      </span>
                      <div>
                        <p className="text-xs text-muted-foreground">Date de naissance</p>
                        <p className="text-sm font-semibold">{selectedLearner.learner.prospect?.dateOfBirth ?? 'Non renseignée'}</p>
                      </div>
                    </div>
                  </div>
                  {selectedLearner.learner.prospect?.comment ? (
                    <div className="flex items-start gap-2.5 rounded-md border border-border bg-muted/30 p-3.5 text-sm">
                      <MessageSquare size={15} className="mt-0.5 shrink-0 text-primary" />
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
            <Card>
              <CardContent className="flex flex-col gap-5 p-6">
                <div className="flex flex-wrap items-center gap-3">
                  <Zap size={17} className="text-primary" />
                  <h3 className="font-display text-lg font-bold tracking-tight">Sessions à venir</h3>
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
                      <span className="tabular w-14 text-center text-sm font-semibold text-muted-foreground">
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
                    <div key={session.id} className="rounded-md border border-primary/20 bg-primary/5 p-4">
                      <span className="mb-2 inline-flex items-center gap-1.5 rounded-md bg-card px-2.5 py-1 text-xs font-semibold">
                        <Calendar size={13} className="text-primary" />
                        {new Date(session.startAt!).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}
                      </span>
                      <h4 className="text-sm font-semibold leading-tight">{session.trainingTitle || 'Session'}</h4>
                      <p className="tabular mt-1.5 text-xs text-muted-foreground">
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

          <Card>
            <CardContent className="p-6">
              <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as typeof activeTab)}>
                <TabsList>
                  <TabsTrigger value="formations">
                    <BookOpen size={15} />
                    Formations ({selectedLearner.trainingRegistrations.length})
                  </TabsTrigger>
                  <TabsTrigger value="sessions">
                    <Calendar size={15} />
                    Sessions ({selectedLearner.sessionRegistrations.length})
                  </TabsTrigger>
                  <TabsTrigger value="activity">
                    <Activity size={15} />
                    Activité récente ({selectedLearner.recentActivities.length})
                  </TabsTrigger>
                  <TabsTrigger value="absences">
                    <AlertTriangle size={15} />
                    Absences ({selectedLearner.absences.length})
                  </TabsTrigger>
                </TabsList>

                <TabsContent value="formations">
                  <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {selectedLearner.trainingRegistrations.map((registration) => (
                      <div key={registration.id} className="overflow-hidden rounded-xl border border-border">
                        <div className="relative flex h-24 items-center justify-center bg-gradient-brand">
                          <BookOpen size={26} className="text-white" />
                          <span className="absolute right-2.5 top-2.5 rounded-md bg-black/40 px-2 py-1 text-xs font-bold text-white">
                            {formatPercentage(registration.progress ?? 0)}
                          </span>
                        </div>
                        <div className="flex flex-col gap-2.5 p-4">
                          <h4 className="text-sm font-semibold leading-tight">{registration.trainingTitle}</h4>
                          <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Clock size={12} />
                            {formatDuration(registration.totalTime)}
                          </span>
                          <Chip variant={stateChipVariant(registration.state)} className="w-fit capitalize">
                            {registration.state}
                          </Chip>
                          <div className="flex items-center gap-2.5">
                            <Progress value={registration.progress ?? 0} className="flex-1" />
                            <span className="tabular text-xs font-semibold text-primary">{formatPercentage(registration.progress ?? 0)}</span>
                          </div>
                          {registration.score !== null && (
                            <p className="text-xs text-muted-foreground">Score: {registration.score}%</p>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </TabsContent>

                <TabsContent value="sessions">
                  <div className="flex flex-col gap-3">
                    {selectedLearner.sessionRegistrations.map((session) => (
                      <div key={session.id} className="rounded-md border border-border p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <h4 className="text-sm font-semibold">{session.trainingTitle || 'Session sans formation'}</h4>
                            <p className="text-xs text-muted-foreground">{session.sessionType || 'Session'}</p>
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
                        <div className="mt-3 flex flex-wrap gap-4 border-t border-border pt-3">
                          <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Calendar size={13} className="text-[#ff6b9d]" />
                            {session.startAt ? formatDateTime(session.startAt) : 'Date non définie'}
                            {session.endAt && ` - ${formatDateTime(session.endAt)}`}
                          </span>
                          {session.eduDuration && (
                            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
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
                      <p className="py-8 text-center text-sm text-muted-foreground">Aucune session enregistrée</p>
                    )}
                  </div>
                </TabsContent>

                <TabsContent value="activity">
                  <div className="flex flex-col gap-3">
                    {selectedLearner.recentActivities.map((activity) => (
                      <div key={activity.id} className="flex gap-3.5 rounded-md border border-border p-4">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                          <Activity size={17} />
                        </span>
                        <div className="min-w-0 flex-1">
                          <h4 className="text-sm font-semibold">{activity.stepTitle}</h4>
                          <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {activity.trainingTitle} › {activity.moduleTitle}
                          </p>
                          <div className="mt-2 flex flex-wrap items-center gap-3">
                            <Chip variant="info" className="capitalize">
                              {activity.stepType || 'Step'}
                            </Chip>
                            <span className="tabular text-xs font-semibold text-primary">
                              {formatDuration(activity.totalTime ?? activity.timeSpent ?? 0)}
                            </span>
                            <span className="text-xs text-muted-foreground">{formatDateTime(activity.activityAt || '')}</span>
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
                      <p className="py-8 text-center text-sm text-muted-foreground">Aucune activité récente</p>
                    )}
                  </div>
                </TabsContent>

                <TabsContent value="absences">
                  <div className="flex flex-col gap-3">
                    {selectedLearner.absences.map((absence) => (
                      <div key={absence.id} className="rounded-md border border-border p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div>
                            <h4 className="text-sm font-semibold">{absence.sessionTitle}</h4>
                            <p className="text-xs text-muted-foreground">
                              {absence.type === 'masterclass' ? 'Masterclass' : 'Session présentiel'}
                            </p>
                          </div>
                          <Chip variant={ABSENCE_STATUS_VARIANT[absence.status]}>
                            {ABSENCE_STATUS_LABEL[absence.status]}
                          </Chip>
                        </div>
                        <div className="mt-3 flex flex-wrap gap-4 border-t border-border pt-3">
                          <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Calendar size={13} className="text-[#ff6b9d]" />
                            {formatDateTime(absence.sessionStartAt)}
                          </span>
                          {absence.justificationSubmittedAt && (
                            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                              <CheckCircle size={13} />
                              Justificatif déposé le {formatDateTime(absence.justificationSubmittedAt)}
                            </span>
                          )}
                          {absence.adminNote && (
                            <span className="text-xs text-muted-foreground">Note : {absence.adminNote}</span>
                          )}
                        </div>
                      </div>
                    ))}
                    {selectedLearner.absences.length === 0 && (
                      <p className="py-8 text-center text-sm text-muted-foreground">Aucune absence enregistrée</p>
                    )}
                  </div>
                </TabsContent>
              </Tabs>
            </CardContent>
          </Card>
        </div>
      )}
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
    <Card>
      <CardContent className="flex flex-col gap-3 p-5">
        <div className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gradient-brand text-white">
            <Icon size={17} />
          </span>
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</span>
        </div>
        <CountUp value={value} className="text-2xl" />
        <Progress value={progress} />
        <span className="text-xs text-muted-foreground">{hint}</span>
      </CardContent>
    </Card>
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
