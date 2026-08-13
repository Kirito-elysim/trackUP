import { useEffect, useMemo, useState, useDeferredValue } from 'react';
import { useAuth } from '../contexts/useAuth';
import { ApiError, apiRequest, apiUrl } from '../lib/api';
import { formatDateTime, formatDuration } from '../lib/format';
import { Clock, User, BookOpen, Calendar, Filter, Download, RefreshCw, Users, TrendingUp, Activity, X, Search } from 'lucide-react';
import type { RiseUpActivityLogsPayload, LearnerSummary } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Chip } from '@/components/ui/chip';
import { CountUp } from '@/components/ui/stat';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { cn, learnerStateChipClass } from '@/lib/utils';

function formatDurationClock(totalSeconds: number): string {
  const seconds = Math.max(0, totalSeconds);
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainingSeconds = seconds % 60;

  return [hours, minutes, remainingSeconds].map((value) => String(value).padStart(2, '0')).join(':');
}

export function RiseUpLogsPage() {
  const { token } = useAuth();
  const [learnerSearchQuery, setLearnerSearchQuery] = useState('');
  const deferredLearnerQuery = useDeferredValue(learnerSearchQuery);
  const [learnerSuggestions, setLearnerSuggestions] = useState<LearnerSummary[]>([]);
  const [showLearnerDropdown, setShowLearnerDropdown] = useState(false);
  const [selectedLearnerEmail, setSelectedLearnerEmail] = useState<string | null>(null);
  const [selectedLearnerName, setSelectedLearnerName] = useState<string | null>(null);
  const [learnerQuery, setLearnerQuery] = useState('');
  const [groupExternalId, setGroupExternalId] = useState('');
  const [learningPathId, setLearningPathId] = useState('');
  const [trainingExternalId, setTrainingExternalId] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(100);
  const [payload, setPayload] = useState<RiseUpActivityLogsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const visibleLearnerSuggestions = deferredLearnerQuery.trim().length < 2 ? [] : learnerSuggestions;

  useEffect(() => {
    if (!token || deferredLearnerQuery.trim().length < 2) {
      return;
    }

    let cancelled = false;

    const loadLearners = async () => {
      const params = new URLSearchParams({
        limit: '20',
        q: deferredLearnerQuery.trim()
      });

      try {
        const data = await apiRequest<LearnerSummary[]>(`/api/learners?${params.toString()}`, { token });

        if (!cancelled) {
          setLearnerSuggestions(data);
        }
      } catch {
        if (!cancelled) {
          setLearnerSuggestions([]);
        }
      }
    };

    void loadLearners();

    return () => {
      cancelled = true;
    };
  }, [deferredLearnerQuery, token]);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('pageSize', String(pageSize));

    if (learnerQuery !== '') {
      params.set('learnerQuery', learnerQuery);
    }
    if (groupExternalId !== '') {
      params.set('groupExternalId', groupExternalId);
    }
    if (learningPathId !== '') {
      params.set('learningPathId', learningPathId);
    }
    if (trainingExternalId !== '') {
      params.set('trainingExternalId', trainingExternalId);
    }
    if (dateFrom !== '') {
      params.set('dateFrom', dateFrom);
    }
    if (dateTo !== '') {
      params.set('dateTo', dateTo);
    }

    return params.toString();
  }, [dateFrom, dateTo, learnerQuery, groupExternalId, learningPathId, page, pageSize, trainingExternalId]);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const data = await apiRequest<RiseUpActivityLogsPayload>(
          `/api/riseup-activity-logs${queryString !== '' ? `?${queryString}` : ''}`,
          { token },
        );

        if (!cancelled) {
          setPayload(data);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
          setPayload(null);
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
  }, [queryString, token]);

  const handleSelectLearner = (learner: LearnerSummary) => {
    setLearnerQuery(learner.email);
    setSelectedLearnerEmail(learner.email);
    setSelectedLearnerName(learner.fullName);
    setLearnerSearchQuery(learner.fullName);
    setShowLearnerDropdown(false);
    setPage(1);
  };

  const handleClearLearner = () => {
    setLearnerQuery('');
    setSelectedLearnerEmail(null);
    setSelectedLearnerName(null);
    setLearnerSearchQuery('');
    setShowLearnerDropdown(false);
    setPage(1);
  };

  const resetFilters = () => {
    setLearnerQuery('');
    setLearnerSearchQuery('');
    setSelectedLearnerEmail(null);
    setSelectedLearnerName(null);
    setShowLearnerDropdown(false);
    setGroupExternalId('');
    setLearningPathId('');
    setTrainingExternalId('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  };

  const exportCsv = async () => {
    if (!payload || !token) {
      return;
    }

    setExporting(true);

    try {
      const params = new URLSearchParams();
      if (learnerQuery !== '') {
        params.set('learnerQuery', learnerQuery);
      }
      if (groupExternalId !== '') {
        params.set('groupExternalId', groupExternalId);
      }
      if (learningPathId !== '') {
        params.set('learningPathId', learningPathId);
      }
      if (trainingExternalId !== '') {
        params.set('trainingExternalId', trainingExternalId);
      }
      if (dateFrom !== '') {
        params.set('dateFrom', dateFrom);
      }
      if (dateTo !== '') {
        params.set('dateTo', dateTo);
      }

      const response = await fetch(
        `${apiUrl('/api/riseup-activity-logs/export')}${
          params.size > 0 ? `?${params.toString()}` : ''
        }`,
        {
          headers: {
            Authorization: `Bearer ${token}`,
          },
        },
      );

      if (!response.ok) {
        throw new ApiError('Export impossible.', response.status);
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `riseup-activity-logs-${new Date().toISOString().slice(0, 10)}.csv`;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Export impossible.');
    } finally {
      setExporting(false);
    }
  };

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <Breadcrumb items={[{ label: 'Conformité' }, { label: 'Historique des activités' }]} />
          <h2 className="mt-1.5 font-display text-3xl font-extrabold tracking-tight">Historique des activités</h2>
          <p className="mt-1.5 text-sm text-muted-foreground">
            Journal complet des sessions e-learning et classes virtuelles signées
          </p>
        </div>
        {payload && (
          <Button onClick={() => void exportCsv()} disabled={exporting}>
            <Download size={15} />
            {exporting ? 'Export en cours...' : 'Exporter CSV'}
          </Button>
        )}
      </div>

      <Card>
        <CardContent className="flex flex-col gap-5 p-6">
          <div className="flex items-center justify-between gap-4 border-b border-border pb-4">
            <div className="flex items-center gap-2.5">
              <Filter size={16} className="text-muted-foreground" />
              <h3 className="font-display text-base font-bold tracking-tight">Filtres</h3>
            </div>
            <Button variant="ghost" size="sm" onClick={resetFilters}>
              <RefreshCw size={14} />
              Réinitialiser
            </Button>
          </div>

          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div className="relative flex flex-col gap-2">
              <label className="flex items-center gap-1.5 text-sm font-semibold">
                <Search size={14} className="text-muted-foreground" />
                Recherche apprenant
              </label>
              <div className="relative">
                <Input
                  type="search"
                  placeholder="Nom ou email de l'apprenant..."
                  value={selectedLearnerName || learnerSearchQuery}
                  onChange={(event) => {
                    setLearnerSearchQuery(event.target.value);
                    setShowLearnerDropdown(event.target.value.length >= 2);
                    if (selectedLearnerEmail) {
                      handleClearLearner();
                    }
                  }}
                  onFocus={() => {
                    if (learnerSearchQuery.length >= 2) {
                      setShowLearnerDropdown(true);
                    }
                  }}
                  className="pr-9"
                />
                {(selectedLearnerEmail || learnerSearchQuery.length > 0) && (
                  <button
                    onClick={handleClearLearner}
                    type="button"
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground transition-colors hover:text-foreground"
                  >
                    <X size={15} />
                  </button>
                )}
              </div>
              {showLearnerDropdown && learnerSearchQuery.length >= 2 && !selectedLearnerEmail && (
                <div className="absolute top-full z-20 mt-1 w-full overflow-hidden rounded-md border border-border bg-card shadow-lg">
                  {visibleLearnerSuggestions.length > 0 ? (
                    <div className="max-h-72 overflow-y-auto p-1.5">
                      {visibleLearnerSuggestions.map((learner) => (
                        <button
                          key={learner.id}
                          onClick={() => handleSelectLearner(learner)}
                          type="button"
                          className="flex w-full items-center gap-3 rounded-md p-2.5 text-left transition-colors hover:bg-muted"
                        >
                          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-gradient-brand text-white">
                            <User size={15} />
                          </span>
                          <span className="min-w-0 flex-1">
                            <strong className="block truncate text-sm font-semibold">{learner.fullName}</strong>
                            <span className="block truncate text-xs text-muted-foreground">{learner.email}</span>
                          </span>
                          <Chip variant="neutral" className={cn('capitalize', learnerStateChipClass(learner.state))}>
                            {learner.state}
                          </Chip>
                        </button>
                      ))}
                    </div>
                  ) : (
                    <div className="flex flex-col items-center gap-2 p-6 text-center text-muted-foreground">
                      <User size={20} className="opacity-40" />
                      <p className="text-sm">Aucun apprenant trouvé</p>
                    </div>
                  )}
                </div>
              )}
            </div>

            <div className="flex flex-col gap-2">
              <label className="flex items-center gap-1.5 text-sm font-semibold">
                <Users size={14} className="text-muted-foreground" />
                Groupe / Classe
              </label>
              <Select
                value={groupExternalId}
                onChange={(event) => {
                  setGroupExternalId(event.target.value);
                  setLearningPathId('');
                  setTrainingExternalId('');
                  setPage(1);
                }}
              >
                <option value="">Tous les groupes</option>
                {(payload?.filters.availableGroups ?? []).map((item) => (
                  <option key={item.externalId} value={item.externalId}>
                    {item.name}
                  </option>
                ))}
              </Select>
            </div>

            <div className="flex flex-col gap-2">
              <label className="flex items-center gap-1.5 text-sm font-semibold">
                <TrendingUp size={14} className="text-muted-foreground" />
                Parcours
              </label>
              <Select
                value={learningPathId}
                onChange={(event) => {
                  setLearningPathId(event.target.value);
                  setTrainingExternalId('');
                  setPage(1);
                }}
              >
                <option value="">{groupExternalId === '' ? 'Tous les parcours' : 'Parcours du groupe'}</option>
                {(payload?.filters.availableLearningPaths ?? []).map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.title}
                  </option>
                ))}
              </Select>
            </div>

            <div className="flex flex-col gap-2">
              <label className="flex items-center gap-1.5 text-sm font-semibold">
                <BookOpen size={14} className="text-muted-foreground" />
                Formation
              </label>
              <Select
                disabled={learningPathId === ''}
                value={trainingExternalId}
                onChange={(event) => {
                  setTrainingExternalId(event.target.value);
                  setPage(1);
                }}
              >
                <option value="">{learningPathId === '' ? 'Sélectionnez un parcours' : 'Toutes les formations'}</option>
                {(payload?.filters.availableTrainings ?? []).map((item) => (
                  <option key={item.externalId} value={item.externalId}>
                    {item.title}
                  </option>
                ))}
              </Select>
            </div>

            <div className="flex flex-col gap-2">
              <label className="flex items-center gap-1.5 text-sm font-semibold">
                <Calendar size={14} className="text-muted-foreground" />
                Date début
              </label>
              <Input
                type="date"
                value={dateFrom}
                onChange={(event) => {
                  setDateFrom(event.target.value);
                  setPage(1);
                }}
              />
            </div>

            <div className="flex flex-col gap-2">
              <label className="flex items-center gap-1.5 text-sm font-semibold">
                <Calendar size={14} className="text-muted-foreground" />
                Date fin
              </label>
              <Input
                type="date"
                value={dateTo}
                onChange={(event) => {
                  setDateTo(event.target.value);
                  setPage(1);
                }}
              />
            </div>

            <div className="flex flex-col gap-2">
              <label className="text-sm font-semibold">Résultats par page</label>
              <Select
                value={pageSize}
                onChange={(event) => {
                  setPageSize(Number(event.target.value));
                  setPage(1);
                }}
              >
                <option value={50}>50 résultats</option>
                <option value={100}>100 résultats</option>
                <option value={200}>200 résultats</option>
                <option value={500}>500 résultats</option>
              </Select>
            </div>
          </div>
        </CardContent>
      </Card>

      {loading && (
        <div className="flex flex-col items-center gap-3 py-14 text-muted-foreground">
          <RefreshCw size={26} className="animate-spin" />
          <p className="text-sm">Chargement de l&rsquo;historique...</p>
        </div>
      )}

      {error && (
        <Card className="border-destructive/30 bg-destructive/5">
          <CardContent className="p-5 text-sm text-destructive">{error}</CardContent>
        </Card>
      )}

      {payload && (
        <>
          {payload.groupContext ? (
            <Card>
              <CardContent className="flex flex-col gap-5 p-6">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Groupe</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">{payload.groupContext.name}</h3>
                  <p className="mt-1 text-sm text-muted-foreground">{payload.groupContext.memberCount} membre(s)</p>
                </div>
                {payload.groupContext.learningPaths.length > 0 ? (
                  <TableShell>
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Parcours</TableHead>
                          <TableHead>Apprenants inscrits</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {payload.groupContext.learningPaths.map((item) => (
                          <TableRow key={item.id}>
                            <TableCell className="text-sm">{item.title}</TableCell>
                            <TableCell className="tabular text-sm font-semibold">{item.learnerCount}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </TableShell>
                ) : (
                  <p className="text-sm text-muted-foreground">Aucun parcours trouvé pour ce groupe (via les inscriptions locales).</p>
                )}
              </CardContent>
            </Card>
          ) : null}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <RiseUpStat icon={Activity} label="Total de logs" value={payload.metrics.logCount.toLocaleString()} hint="E-learning + Sessions signées" delay={0} />
            <RiseUpStat icon={Users} label="Apprenants" value={payload.metrics.uniqueLearnersCount} hint="Utilisateurs uniques" delay={80} />
            <RiseUpStat icon={BookOpen} label="Formations" value={payload.metrics.uniqueTrainingsCount} hint="Formations distinctes" delay={160} />
            <RiseUpStat
              icon={Clock}
              label="Temps total"
              value={formatDuration(payload.metrics.totalDurationMinutes)}
              hint={formatDurationClock(payload.metrics.totalDurationSeconds)}
              delay={240}
            />
          </div>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Journal d&rsquo;activité</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">{payload.rows.length} log(s) filtré(s)</h3>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {payload.pagination.totalRows} ligne(s) au total · page {payload.pagination.page} sur {payload.pagination.totalPages}
                  </p>
                </div>
                <p className="text-sm text-muted-foreground">Dernier import : {formatDateTime(payload.lastImportAt)}</p>
              </div>

              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Type</TableHead>
                      <TableHead>Connexion</TableHead>
                      <TableHead>Déconnexion</TableHead>
                      <TableHead>Durée</TableHead>
                      <TableHead>Appareil</TableHead>
                      <TableHead>Apprenant</TableHead>
                      <TableHead>Formation</TableHead>
                      <TableHead>Fichier source</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {payload.rows.map((row) => (
                      <TableRow key={row.id}>
                        <TableCell>
                          <Chip variant={row.sourceType === 'session' ? 'primary' : 'neutral'}>
                            {row.sourceType === 'session' ? 'Session' : 'E-learning'}
                          </Chip>
                        </TableCell>
                        <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{formatDateTime(row.loginAt)}</TableCell>
                        <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{formatDateTime(row.logoutAt)}</TableCell>
                        <TableCell>
                          <strong className="tabular block text-sm font-semibold">{formatDuration(row.durationMinutes)}</strong>
                          <span className="tabular text-xs text-muted-foreground">{formatDurationClock(row.durationSeconds)}</span>
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">{row.device ?? 'N/A'}</TableCell>
                        <TableCell>
                          <strong className="block text-sm font-semibold">{row.learnerFullName}</strong>
                          <span className="text-xs text-muted-foreground">{row.learnerEmail ?? 'Email indisponible'}</span>
                        </TableCell>
                        <TableCell>
                          <strong className="block text-sm font-semibold">{row.trainingTitle}</strong>
                          <span className="text-xs text-muted-foreground">ID Rise Up {row.trainingExternalId}</span>
                        </TableCell>
                        <TableCell>
                          <strong className="block text-sm font-semibold">{row.sourceFileName}</strong>
                          <span className="text-xs text-muted-foreground">{formatDateTime(row.sourceImportedAt)}</span>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableShell>

              {payload.rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  Aucun log importé pour ces filtres. Importe un export Rise Up avec la commande console pour alimenter
                  cette vue.
                </p>
              ) : null}

              {payload.pagination.totalPages > 1 ? (
                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-border pt-4">
                  <p className="text-sm text-muted-foreground">
                    Affichage de {payload.rows.length} ligne(s) sur {payload.pagination.totalRows}
                  </p>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={payload.pagination.page <= 1 || loading}
                      onClick={() => setPage((current) => Math.max(1, current - 1))}
                    >
                      Précédent
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={payload.pagination.page >= payload.pagination.totalPages || loading}
                      onClick={() => setPage((current) => Math.min(payload.pagination.totalPages, current + 1))}
                    >
                      Suivant
                    </Button>
                  </div>
                </div>
              ) : null}
            </CardContent>
          </Card>
        </>
      )}
    </section>
  );
}

function RiseUpStat({
  icon: Icon,
  label,
  value,
  hint,
  delay,
}: {
  icon: typeof Activity;
  label: string;
  value: string | number;
  hint: string;
  delay: number;
}) {
  return (
    <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: `${delay}ms` }}>
      <CardContent className="flex items-center gap-4 p-5">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-brand text-white">
          <Icon size={20} />
        </span>
        <div className="min-w-0">
          <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p>
          <CountUp value={value} className="text-xl" />
          <p className="truncate text-xs text-muted-foreground">{hint}</p>
        </div>
      </CardContent>
    </Card>
  );
}
