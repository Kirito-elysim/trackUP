import { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Calendar,
  CheckCircle2,
  ChevronDown,
  Clock,
  RefreshCw,
  Search,
  SlidersHorizontal,
  XCircle,
} from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime } from '../lib/format';
import { compareValues } from '../lib/sort';
import type { Absence, AbsencesPayload, AbsenceStatus } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Chip } from '@/components/ui/chip';
import { CountUp } from '@/components/ui/stat';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { SortableHead, Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { cn } from '@/lib/utils';

const EMPTY_FILTERS = {
  groupExternalId: '',
  status: '',
  type: '',
  dateFrom: '',
  dateTo: '',
};

const STATUS_LABEL: Record<AbsenceStatus, string> = {
  en_attente: 'En attente',
  justifiee: 'Justifiée',
  non_justifiee: 'Non justifiée',
  autre: 'Autre',
};

const STATUS_VARIANT: Record<AbsenceStatus, 'neutral' | 'success' | 'destructive' | 'info'> = {
  en_attente: 'neutral',
  justifiee: 'success',
  non_justifiee: 'destructive',
  autre: 'info',
};

export function AbsencesPage() {
  const { token } = useAuth();
  const [learnerQuery, setLearnerQuery] = useState('');
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [dateSortDirection, setDateSortDirection] = useState<'asc' | 'desc'>('desc');
  const [page, setPage] = useState(1);
  const [payload, setPayload] = useState<AbsencesPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [savingId, setSavingId] = useState<number | null>(null);

  const activeFilterCount = Object.values(filters).filter((value) => value !== '').length;

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('pageSize', '50');
    if (learnerQuery !== '') params.set('learnerQuery', learnerQuery);
    if (filters.groupExternalId !== '') params.set('groupExternalId', filters.groupExternalId);
    if (filters.status !== '') params.set('status', filters.status);
    if (filters.type !== '') params.set('type', filters.type);
    if (filters.dateFrom !== '') params.set('dateFrom', filters.dateFrom);
    if (filters.dateTo !== '') params.set('dateTo', filters.dateTo);
    return params.toString();
  }, [filters, learnerQuery, page]);

  useEffect(() => {
    if (!token) return;

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const data = await apiRequest<AbsencesPayload>(`/api/admin/absences?${queryString}`, { token });
        if (!cancelled) setPayload(data);
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
          setPayload(null);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [queryString, token]);

  const resetFilters = () => {
    setFilters(EMPTY_FILTERS);
    setPage(1);
  };

  const updateAbsence = async (id: number, changes: { status?: AbsenceStatus; adminNote?: string }) => {
    if (!token) return;

    setSavingId(id);
    try {
      const updated = await apiRequest<Absence>(`/api/admin/absences/${id}`, {
        method: 'PATCH',
        token,
        body: changes,
      });

      setPayload((current) =>
        current
          ? {
              ...current,
              absences: current.absences.map((absence) =>
                absence.id === id
                  ? {
                      ...absence,
                      status: updated.status,
                      adminNote: updated.adminNote,
                      validatedAt: updated.validatedAt,
                      validatedByName: updated.validatedByName,
                    }
                  : absence,
              ),
            }
          : current,
      );
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Mise à jour impossible.');
    } finally {
      setSavingId(null);
    }
  };

  const sortedAbsences = useMemo(() => {
    const rows = [...(payload?.absences ?? [])];
    rows.sort((left, right) => compareValues(left.session.startAt, right.session.startAt, dateSortDirection));
    return rows;
  }, [dateSortDirection, payload?.absences]);

  const handleDateSort = () => {
    setDateSortDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
  };

  return (
    <section className="flex flex-col gap-8">
      <div>
        <Breadcrumb items={[{ label: 'Conformité' }, { label: 'Absences' }]} />
        <h2 className="mt-1.5 font-display text-3xl font-extrabold tracking-tight">Absences</h2>
        <p className="mt-1.5 text-sm text-muted-foreground">
          Suivi des absences aux masterclass et sessions présentielles, justificatifs et validation
        </p>
      </div>

      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-3">
          <div className="relative min-w-[220px] flex-1">
            <Search size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Rechercher un apprenant (nom ou email)..."
              value={learnerQuery}
              onChange={(event) => {
                setLearnerQuery(event.target.value);
                setPage(1);
              }}
              className="pl-10"
            />
          </div>
          <Button variant="outline" onClick={() => setFiltersOpen((current) => !current)} className="gap-2">
            <SlidersHorizontal size={15} />
            Filtres avancés
            {activeFilterCount > 0 ? <Chip variant="info">{activeFilterCount}</Chip> : null}
            <ChevronDown size={14} className={cn('transition-transform', filtersOpen && 'rotate-180')} />
          </Button>
          {activeFilterCount > 0 ? (
            <Button variant="ghost" onClick={resetFilters}>
              <RefreshCw size={14} />
              Réinitialiser
            </Button>
          ) : null}
        </div>

        {filtersOpen ? (
          <Card className="p-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Groupe / Classe</span>
                <Select
                  value={filters.groupExternalId}
                  onChange={(event) => {
                    setFilters((current) => ({ ...current, groupExternalId: event.target.value }));
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
              </label>

              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Statut</span>
                <Select
                  value={filters.status}
                  onChange={(event) => {
                    setFilters((current) => ({ ...current, status: event.target.value }));
                    setPage(1);
                  }}
                >
                  <option value="">Tous les statuts</option>
                  {Object.entries(STATUS_LABEL).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </Select>
              </label>

              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Type</span>
                <Select
                  value={filters.type}
                  onChange={(event) => {
                    setFilters((current) => ({ ...current, type: event.target.value }));
                    setPage(1);
                  }}
                >
                  <option value="">Tous les types</option>
                  <option value="masterclass">Masterclass</option>
                  <option value="presentiel">Session présentiel</option>
                </Select>
              </label>

              <div />

              <label className="flex flex-col gap-1.5">
                <span className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                  <Calendar size={13} />
                  Date début
                </span>
                <Input
                  type="date"
                  value={filters.dateFrom}
                  onChange={(event) => {
                    setFilters((current) => ({ ...current, dateFrom: event.target.value }));
                    setPage(1);
                  }}
                />
              </label>

              <label className="flex flex-col gap-1.5">
                <span className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                  <Calendar size={13} />
                  Date fin
                </span>
                <Input
                  type="date"
                  value={filters.dateTo}
                  onChange={(event) => {
                    setFilters((current) => ({ ...current, dateTo: event.target.value }));
                    setPage(1);
                  }}
                />
              </label>
            </div>
          </Card>
        ) : null}
      </div>

      {loading && (
        <div className="flex flex-col items-center gap-3 py-14 text-muted-foreground">
          <RefreshCw size={26} className="animate-spin" />
          <p className="text-sm">Chargement des absences...</p>
        </div>
      )}

      {error && (
        <Card className="border-destructive/30 bg-destructive/5">
          <CardContent className="p-5 text-sm text-destructive">{error}</CardContent>
        </Card>
      )}

      {payload && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AbsenceStat icon={AlertTriangle} label="Total" value={payload.stats.total} hint="Absences filtrées" delay={0} />
            <AbsenceStat
              icon={Clock}
              label="En attente"
              value={payload.stats.byStatus.en_attente}
              hint="Justificatif non encore traité"
              delay={80}
            />
            <AbsenceStat
              icon={CheckCircle2}
              label="Justifiées"
              value={payload.stats.byStatus.justifiee}
              hint="Validées par un admin"
              delay={160}
            />
            <AbsenceStat
              icon={XCircle}
              label="Non justifiées"
              value={payload.stats.byStatus.non_justifiee}
              hint="Rejetées par un admin"
              delay={240}
            />
          </div>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Journal des absences</p>
                <h3 className="font-display text-lg font-bold tracking-tight">
                  {payload.pagination.totalRows} absence(s) au total
                </h3>
              </div>

              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Apprenant</TableHead>
                      <SortableHead active direction={dateSortDirection} onClick={handleDateSort}>
                        Date
                      </SortableHead>
                      <TableHead>Session</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Justificatif</TableHead>
                      <TableHead>Statut</TableHead>
                      <TableHead>Note admin</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {sortedAbsences.map((absence) => (
                      <TableRow key={absence.id}>
                        <TableCell>
                          <strong className="block text-sm font-semibold">{absence.learner.fullName}</strong>
                          <span className="text-xs text-muted-foreground">{absence.learner.email ?? 'Email indisponible'}</span>
                        </TableCell>
                        <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                          {formatDateTime(absence.session.startAt)}
                        </TableCell>
                        <TableCell>
                          <strong className="block text-sm font-semibold">{absence.session.title}</strong>
                        </TableCell>
                        <TableCell>
                          <Chip variant={absence.type === 'masterclass' ? 'primary' : 'info'}>
                            {absence.type === 'masterclass' ? 'Masterclass' : 'Présentiel'}
                          </Chip>
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {absence.justificationFileOriginalName ?? 'Aucun'}
                        </TableCell>
                        <TableCell>
                          <Select
                            value={absence.status}
                            disabled={savingId === absence.id}
                            onChange={(event) => void updateAbsence(absence.id, { status: event.target.value as AbsenceStatus })}
                            className="w-fit"
                          >
                            {Object.entries(STATUS_LABEL).map(([value, label]) => (
                              <option key={value} value={value}>
                                {label}
                              </option>
                            ))}
                          </Select>
                          <Chip variant={STATUS_VARIANT[absence.status]} className="mt-1.5 w-fit">
                            {STATUS_LABEL[absence.status]}
                          </Chip>
                        </TableCell>
                        <TableCell>
                          <Input
                            defaultValue={absence.adminNote ?? ''}
                            placeholder="Ajouter une note..."
                            disabled={savingId === absence.id}
                            onBlur={(event) => {
                              if (event.target.value !== (absence.adminNote ?? '')) {
                                void updateAbsence(absence.id, { adminNote: event.target.value });
                              }
                            }}
                            className="min-w-[180px] text-xs"
                          />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableShell>

              {payload.absences.length === 0 ? (
                <p className="text-sm text-muted-foreground">Aucune absence pour ces filtres.</p>
              ) : null}

              {payload.pagination.totalPages > 1 ? (
                <div className="flex flex-wrap items-center justify-between gap-4 border-t border-border pt-4">
                  <p className="text-sm text-muted-foreground">
                    Page {payload.pagination.page} sur {payload.pagination.totalPages}
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

function AbsenceStat({
  icon: Icon,
  label,
  value,
  hint,
  delay,
}: {
  icon: typeof AlertTriangle;
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
