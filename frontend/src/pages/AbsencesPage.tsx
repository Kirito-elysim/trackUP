import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  ArrowUpDown,
  CheckCircle2,
  ChevronRight,
  Download,
  FileText,
  Filter,
  Image as ImageIcon,
  Search,
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
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { AbsAvatar, AbsStatusChip, AbsTypeChip } from '@/components/absences/badges';

const STATUS_LABEL: Record<AbsenceStatus, string> = {
  en_attente: 'En attente',
  justifiee: 'Justifiée',
  non_justifiee: 'Non justifiée',
  autre: 'Autre',
};

const STATUS_TABS: Array<{ key: AbsenceStatus | 'all'; label: string }> = [
  { key: 'all', label: 'Toutes' },
  { key: 'en_attente', label: 'En attente' },
  { key: 'justifiee', label: 'Justifiées' },
  { key: 'non_justifiee', label: 'Non justifiées' },
  { key: 'autre', label: 'Autre' },
];

type SortKey = 'date' | 'learner' | 'status';

function periodToDateFrom(period: string): string {
  if (period === '') return '';
  const days = Number(period);
  const date = new Date();
  date.setDate(date.getDate() - days);
  return date.toISOString().slice(0, 10);
}

function downloadCsv(rows: Absence[]) {
  const header = ['Apprenant', 'Email', 'Session', 'Date', 'Type', 'Statut', 'Justificatif'];
  const lines = rows.map((absence) =>
    [
      absence.learner.fullName,
      absence.learner.email ?? '',
      absence.session.title,
      absence.session.startAt ?? '',
      absence.type,
      STATUS_LABEL[absence.status],
      absence.justificationFileOriginalName ?? '',
    ]
      .map((value) => `"${String(value).replace(/"/g, '""')}"`)
      .join(';'),
  );
  const csv = [header.join(';'), ...lines].join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `absences-${new Date().toISOString().slice(0, 10)}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
}

export function AbsencesPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [learnerQuery, setLearnerQuery] = useState('');
  const [groupExternalId, setGroupExternalId] = useState('');
  const [type, setType] = useState('');
  const [period, setPeriod] = useState('');
  const [status, setStatus] = useState<AbsenceStatus | 'all'>('all');
  const [sort, setSort] = useState<SortKey>('date');
  const [page, setPage] = useState(1);
  const [payload, setPayload] = useState<AbsencesPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [savingId, setSavingId] = useState<number | null>(null);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('pageSize', '50');
    if (learnerQuery !== '') params.set('learnerQuery', learnerQuery);
    if (groupExternalId !== '') params.set('groupExternalId', groupExternalId);
    if (type !== '') params.set('type', type);
    if (status !== 'all') params.set('status', status);
    const dateFrom = periodToDateFrom(period);
    if (dateFrom !== '') params.set('dateFrom', dateFrom);
    return params.toString();
  }, [groupExternalId, learnerQuery, page, period, status, type]);

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
    rows.sort((left, right) => {
      if (sort === 'date') return compareValues(left.session.startAt, right.session.startAt, 'desc');
      if (sort === 'learner') return compareValues(left.learner.fullName, right.learner.fullName, 'asc');
      return compareValues(left.status, right.status, 'asc');
    });
    return rows;
  }, [payload?.absences, sort]);

  return (
    <section className="flex flex-col gap-5">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <Breadcrumb items={[{ label: 'Absences' }, { label: 'Liste' }]} />
          <h2 className="mt-1.5 font-display text-3xl font-extrabold tracking-tight">Onglet Absences</h2>
          <p className="mt-1.5 text-sm text-muted-foreground">
            Gérez et validez les justificatifs, filtrez par apprenant, groupe, période et statut.
          </p>
        </div>
        <Button
          variant="outline"
          onClick={() => downloadCsv(sortedAbsences)}
          disabled={!payload || sortedAbsences.length === 0}
        >
          <Download size={15} /> Exporter cette page
        </Button>
      </div>

      <Card className="flex flex-col gap-3 p-4">
        <div className="flex flex-col gap-3 lg:flex-row">
          <div className="relative flex-1">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              className="pl-9"
              placeholder="Rechercher un apprenant (nom ou email)..."
              value={learnerQuery}
              onChange={(event) => {
                setLearnerQuery(event.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:flex">
            <Select
              className="lg:w-48"
              value={groupExternalId}
              onChange={(event) => {
                setGroupExternalId(event.target.value);
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
            <Select
              className="lg:w-40"
              value={type}
              onChange={(event) => {
                setType(event.target.value);
                setPage(1);
              }}
            >
              <option value="">Tous types</option>
              <option value="masterclass">Masterclass</option>
              <option value="presentiel">Présentiel</option>
            </Select>
            <Select
              className="lg:w-40"
              value={period}
              onChange={(event) => {
                setPeriod(event.target.value);
                setPage(1);
              }}
            >
              <option value="">Toutes périodes</option>
              <option value="7">7 derniers jours</option>
              <option value="30">30 derniers jours</option>
            </Select>
            <Select className="lg:w-44" value={sort} onChange={(event) => setSort(event.target.value as SortKey)}>
              <option value="date">Trier par date</option>
              <option value="learner">Trier par apprenant</option>
              <option value="status">Trier par statut</option>
            </Select>
          </div>
        </div>

        <div className="flex items-center gap-1.5 overflow-x-auto pb-1">
          {STATUS_TABS.map((tab) => {
            const active = status === tab.key;
            const count = tab.key === 'all' ? (payload?.stats.total ?? 0) : (payload?.stats.byStatus[tab.key] ?? 0);
            return (
              <button
                key={tab.key}
                onClick={() => {
                  setStatus(tab.key);
                  setPage(1);
                }}
                className={`flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-xl px-3.5 py-2 text-sm font-medium transition-colors ${
                  active ? 'bg-abs-ink-900 text-white' : 'bg-abs-ink-50 text-abs-ink-600 hover:bg-abs-ink-100'
                }`}
              >
                {tab.label}
                <span
                  className={`rounded-full px-1.5 py-0.5 text-[10px] font-bold ${
                    active ? 'bg-white/20' : 'bg-white text-abs-ink-500'
                  }`}
                >
                  {count}
                </span>
              </button>
            );
          })}
        </div>
      </Card>

      {loading ? (
        <div className="flex flex-col items-center gap-3 py-14 text-muted-foreground">
          <p className="text-sm">Chargement des absences...</p>
        </div>
      ) : null}

      {error ? (
        <Card className="border-abs-danger-200 bg-abs-danger-50">
          <CardContent className="p-5 text-sm text-abs-danger-700">{error}</CardContent>
        </Card>
      ) : null}

      {payload ? (
        <Card className="overflow-hidden p-0">
          <TableShell>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Apprenant</TableHead>
                  <TableHead>Session</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Statut</TableHead>
                  <TableHead>Justificatif</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sortedAbsences.map((absence) => (
                  <TableRow key={absence.id}>
                    <TableCell>
                      <button
                        onClick={() => navigate(`/learners/${absence.learner.id}`)}
                        className="flex items-center gap-2.5 text-left"
                      >
                        <AbsAvatar name={absence.learner.fullName} size="sm" />
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium hover:text-abs-brand-600">{absence.learner.fullName}</p>
                          <p className="truncate text-xs text-muted-foreground">{absence.learner.email ?? 'Email indisponible'}</p>
                        </div>
                      </button>
                    </TableCell>
                    <TableCell className="max-w-[220px]">
                      <button
                        onClick={() => navigate(`/absences/${absence.id}`)}
                        className="block truncate text-left text-sm text-foreground hover:text-abs-brand-600"
                      >
                        {absence.session.title}
                      </button>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDateTime(absence.session.startAt)}
                    </TableCell>
                    <TableCell>
                      <AbsTypeChip type={absence.type} />
                    </TableCell>
                    <TableCell>
                      <AbsStatusChip status={absence.status} />
                    </TableCell>
                    <TableCell>
                      {absence.justificationFileOriginalName ? (
                        <span className="inline-flex items-center gap-1.5 text-xs text-foreground">
                          {absence.justificationFileOriginalName.toLowerCase().endsWith('.pdf') ? (
                            <FileText size={14} className="text-abs-danger-500" />
                          ) : (
                            <ImageIcon size={14} className="text-abs-brand-500" />
                          )}
                          <span className="max-w-[120px] truncate">{absence.justificationFileOriginalName}</span>
                        </span>
                      ) : (
                        <span className="text-xs text-muted-foreground">&mdash;</span>
                      )}
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center justify-end gap-1">
                        <button
                          title="Valider"
                          disabled={savingId === absence.id}
                          onClick={() => void updateAbsence(absence.id, { status: 'justifiee' })}
                          className="flex h-8 w-8 items-center justify-center rounded-md text-abs-success-700 transition-colors hover:bg-abs-success-50 disabled:opacity-50"
                        >
                          <CheckCircle2 size={16} />
                        </button>
                        <button
                          title="Rejeter"
                          disabled={savingId === absence.id}
                          onClick={() => void updateAbsence(absence.id, { status: 'non_justifiee' })}
                          className="flex h-8 w-8 items-center justify-center rounded-md text-abs-danger-700 transition-colors hover:bg-abs-danger-50 disabled:opacity-50"
                        >
                          <XCircle size={16} />
                        </button>
                        <button
                          title="Détail"
                          onClick={() => navigate(`/absences/${absence.id}`)}
                          className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-abs-ink-50"
                        >
                          <ChevronRight size={16} />
                        </button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableShell>

          {sortedAbsences.length === 0 ? (
            <div className="flex flex-col items-center gap-2 py-14 text-center text-muted-foreground">
              <Filter size={24} />
              <p className="text-sm">Aucune absence ne correspond à ces filtres.</p>
            </div>
          ) : null}

          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 text-xs text-muted-foreground">
            <span>
              {sortedAbsences.length} résultat{sortedAbsences.length > 1 ? 's' : ''} &middot; page {payload.pagination.page} sur{' '}
              {payload.pagination.totalPages}
            </span>
            <div className="flex items-center gap-3">
              <span className="inline-flex items-center gap-1">
                <ArrowUpDown size={12} /> {sort === 'date' ? 'Date' : sort === 'learner' ? 'Apprenant' : 'Statut'}
              </span>
              {payload.pagination.totalPages > 1 ? (
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
              ) : null}
            </div>
          </div>
        </Card>
      ) : null}
    </section>
  );
}
