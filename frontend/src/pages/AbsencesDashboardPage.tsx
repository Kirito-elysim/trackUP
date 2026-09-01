import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CalendarX2, CheckCircle2, ChevronRight, Clock, FileCheck2, Info, Pencil, ShieldAlert, XCircle } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip as RechartsTooltip, XAxis, YAxis } from 'recharts';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime } from '../lib/format';
import type { AbsenceEvolutionGranularity, AbsenceEvolutionPayload, AbsenceStatus, AbsencesDashboardPayload } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CountUp } from '@/components/ui/stat';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { AbsAvatar, AbsStatusChip, AbsTypeChip } from '@/components/absences/badges';
import { ABS_STATUS_META } from '@/components/absences/meta';
import { cn } from '@/lib/utils';

const STATUS_COLOR: Record<AbsenceStatus, string> = {
  en_attente: 'var(--color-abs-warning-500)',
  justifiee: 'var(--color-abs-success-500)',
  non_justifiee: 'var(--color-abs-danger-500)',
  autre: 'var(--color-abs-ink-400)',
};

const EVOLUTION_GRANULARITIES: Array<{ value: AbsenceEvolutionGranularity; label: string }> = [
  { value: 'year', label: 'Année' },
  { value: 'month', label: 'Mois' },
  { value: 'day', label: 'Jour' },
];

function formatPeriodLabel(period: string, granularity: AbsenceEvolutionGranularity): string {
  if (granularity === 'year') return period;

  if (granularity === 'month') {
    const [year, month] = period.split('-');
    const date = new Date(Number(year), Number(month) - 1, 1);
    return date.toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
  }

  const [year, month, day] = period.split('-');
  const date = new Date(Number(year), Number(month) - 1, Number(day));
  return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
}

const STATUS_LABEL: Record<AbsenceStatus, string> = {
  en_attente: 'En attente',
  justifiee: 'Justifiée',
  non_justifiee: 'Non justifiée',
  autre: 'Autre',
};

const STATUS_ORDER: AbsenceStatus[] = ['en_attente', 'justifiee', 'non_justifiee', 'autre'];

export function AbsencesDashboardPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [payload, setPayload] = useState<AbsencesDashboardPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [evolutionGranularity, setEvolutionGranularity] = useState<AbsenceEvolutionGranularity>('month');
  const [evolution, setEvolution] = useState<AbsenceEvolutionPayload | null>(null);
  const [evolutionLoading, setEvolutionLoading] = useState(true);

  const [editingResetDate, setEditingResetDate] = useState(false);
  const [resetDateValue, setResetDateValue] = useState('');
  const [savingResetDate, setSavingResetDate] = useState(false);
  const [resetDateError, setResetDateError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) return;

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const data = await apiRequest<AbsencesDashboardPayload>('/api/admin/absences/dashboard', { token });
        if (!cancelled) setPayload(data);
      } catch (caught) {
        if (!cancelled) setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [token]);

  useEffect(() => {
    if (!token) return;

    let cancelled = false;

    const load = async () => {
      setEvolutionLoading(true);

      try {
        const data = await apiRequest<AbsenceEvolutionPayload>(
          `/api/admin/absences/evolution?granularity=${evolutionGranularity}`,
          { token },
        );
        if (!cancelled) setEvolution(data);
      } catch {
        if (!cancelled) setEvolution(null);
      } finally {
        if (!cancelled) setEvolutionLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [token, evolutionGranularity]);

  const openResetDateEditor = () => {
    setResetDateValue(payload?.streakTracking.resetAt ? payload.streakTracking.resetAt.slice(0, 10) : '');
    setResetDateError(null);
    setEditingResetDate(true);
  };

  const handleSaveResetDate = async () => {
    if (!token || resetDateValue === '') return;

    setSavingResetDate(true);
    setResetDateError(null);

    try {
      const result = await apiRequest<{ resetAt: string; affectedLearnersCount: number }>(
        '/api/admin/absences/streak-tracking/reset',
        { token, method: 'POST', body: { resetAt: resetDateValue } },
      );
      setPayload((prev) => (prev ? { ...prev, streakTracking: result } : prev));
      setEditingResetDate(false);
    } catch (caught) {
      setResetDateError(caught instanceof ApiError ? caught.message : 'Impossible de mettre à jour la date.');
    } finally {
      setSavingResetDate(false);
    }
  };

  return (
    <section className="flex flex-col gap-8">
      <div>
        <Breadcrumb items={[{ label: 'Absences' }, { label: 'Tableau de bord' }]} />
        <h2 className="mt-1.5 font-display text-3xl font-extrabold tracking-tight">Tableau de bord</h2>
      </div>

      <Card className="overflow-hidden border-0 bg-gradient-abs-hero text-white">
        <CardContent className="relative flex flex-col gap-5 p-8">
          <div
            className="pointer-events-none absolute inset-0 opacity-20"
            style={{ backgroundImage: 'radial-gradient(circle at 80% 20%, rgba(51,133,252,.5), transparent 50%)' }}
          />
          <span className="relative flex w-fit items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-abs-brand-300">
            <ShieldAlert size={14} /> Module de conformité
          </span>
          <h3 className="relative font-display text-2xl font-extrabold leading-tight tracking-tight">
            Gestion &amp; suivi des absences
          </h3>
          <p className="relative max-w-xl text-sm text-abs-ink-300">
            Centralisation des absences en masterclass et sessions présentiel, workflow justificatif
            automatisé et alertes disciplinaires pour l&rsquo;équipe pédagogique.
          </p>
          <div className="relative flex flex-wrap gap-3">
            <Button className="bg-white text-abs-ink-900 hover:bg-abs-ink-100" onClick={() => navigate('/absences')}>
              Gérer les absences
            </Button>
            <Button variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20" onClick={() => navigate('/absences/alertes')}>
              <ShieldAlert size={15} />
              Alertes actives {payload && payload.activeAlertsCount > 0 ? `(${payload.activeAlertsCount})` : ''}
            </Button>
            <Button variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20" onClick={openResetDateEditor}>
              <Pencil size={15} />
              Date de suivi
            </Button>
          </div>
        </CardContent>
      </Card>

      {loading ? <Card><CardContent className="p-5 text-sm text-muted-foreground">Chargement...</CardContent></Card> : null}
      {error ? <Card className="border-destructive/30 bg-destructive/5"><CardContent className="p-5 text-sm text-destructive">{error}</CardContent></Card> : null}

      {payload ? (
        <>
          {payload.pendingReviewCount > 0 ? (
            <button
              type="button"
              onClick={() => navigate('/absences?pendingReview=1')}
              className="flex items-center gap-3 rounded-xl border border-abs-brand-200 bg-abs-brand-50 p-4 text-left text-sm text-abs-brand-800 transition-colors hover:bg-abs-brand-100"
            >
              <FileCheck2 size={16} className="shrink-0" />
              <p className="flex-1">
                <strong>{payload.pendingReviewCount}</strong> justificatif{payload.pendingReviewCount > 1 ? 's' : ''}{' '}
                déposé{payload.pendingReviewCount > 1 ? 's' : ''} par des apprenants et en attente de vérification.
              </p>
              <ChevronRight size={15} className="shrink-0" />
            </button>
          ) : null}

          {editingResetDate ? (
            <div className="flex items-start gap-3 rounded-xl border border-abs-warning-200 bg-abs-warning-50 p-4 text-sm text-abs-warning-800">
              <Info size={16} className="mt-0.5 shrink-0" />
              <div className="flex flex-1 flex-col gap-2.5">
                <p className="text-xs text-abs-warning-700">
                  {payload.streakTracking.resetAt
                    ? `Nouvelle date de départ du suivi, appliquée aux ${payload.streakTracking.affectedLearnersCount} apprenant(s) déjà suivi(s).`
                    : "Aucun apprenant n'est encore suivi (aucun compteur n'a jamais été réinitialisé) — cette date sera appliquée à tous les apprenants."}
                </p>
                <div className="flex flex-wrap items-center gap-2.5">
                  <Input
                    type="date"
                    value={resetDateValue}
                    max={new Date().toISOString().slice(0, 10)}
                    onChange={(event) => setResetDateValue(event.target.value)}
                    className="w-auto border-abs-warning-300 bg-white text-abs-ink-900"
                  />
                  <Button size="sm" disabled={savingResetDate || resetDateValue === ''} onClick={() => void handleSaveResetDate()}>
                    {savingResetDate ? 'Enregistrement...' : 'Enregistrer'}
                  </Button>
                  <Button size="sm" variant="ghost" disabled={savingResetDate} onClick={() => setEditingResetDate(false)}>
                    Annuler
                  </Button>
                </div>
                {resetDateError ? <p className="text-xs text-abs-danger-700">{resetDateError}</p> : null}
              </div>
            </div>
          ) : payload.streakTracking.resetAt ? (
            <div className="flex items-start gap-3 rounded-xl border border-abs-warning-200 bg-abs-warning-50 p-4 text-sm text-abs-warning-800">
              <Info size={16} className="mt-0.5 shrink-0" />
              <p className="flex-1">
                Le suivi des relances disciplinaires (3 absences masterclass consécutives) ne compte que
                les absences détectées à partir du{' '}
                <strong>{formatDateTime(payload.streakTracking.resetAt)}</strong> pour{' '}
                {payload.streakTracking.affectedLearnersCount} apprenant(s) dont le compteur a été
                réinitialisé — les absences antérieures à cette date, même en attente, ne déclenchent
                pas d&rsquo;alerte.
              </p>
            </div>
          ) : null}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <DashboardStat icon={CalendarX2} tone="brand" label="Total absences" value={payload.stats.total} hint="Masterclass + présentiel" delay={0} />
            <DashboardStat icon={Clock} tone="warning" label="En attente" value={payload.stats.byStatus.en_attente} hint="Justificatif attendu" delay={80} />
            <DashboardStat icon={CheckCircle2} tone="success" label="Justifiées" value={payload.stats.byStatus.justifiee} hint="Validées par l'admin" delay={160} />
            <DashboardStat icon={XCircle} tone="danger" label="Non justifiées" value={payload.stats.byStatus.non_justifiee} hint="Rejetées ou sans réponse" delay={240} />
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card>
              <CardContent className="flex flex-col gap-4 p-6">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Vue d'ensemble</p>
                  <h3 className="font-display text-base font-bold tracking-tight">Répartition par statut</h3>
                </div>
                {payload.stats.total > 0 ? (
                  <ResponsiveContainer width="100%" height={160}>
                    <PieChart>
                      <Pie
                        data={STATUS_ORDER.map((status) => ({ status, value: payload.stats.byStatus[status] }))}
                        dataKey="value"
                        nameKey="status"
                        innerRadius={42}
                        outerRadius={64}
                        paddingAngle={2}
                        strokeWidth={0}
                      >
                        {STATUS_ORDER.map((status) => (
                          <Cell key={status} fill={STATUS_COLOR[status]} />
                        ))}
                      </Pie>
                      <RechartsTooltip
                        contentStyle={{
                          backgroundColor: 'var(--color-card)',
                          border: '1px solid var(--color-border)',
                          borderRadius: 8,
                          fontFamily: 'var(--font-sans)',
                          fontSize: 12,
                        }}
                        formatter={(value, name) => [Number(value), STATUS_LABEL[name as AbsenceStatus] ?? String(name)]}
                      />
                    </PieChart>
                  </ResponsiveContainer>
                ) : null}
                <ul className="flex flex-col gap-2.5">
                  {STATUS_ORDER.map((status) => (
                    <li key={status} className="flex items-center justify-between text-sm">
                      <span className="flex items-center gap-2.5 text-foreground">
                        <span className={cn('h-2.5 w-2.5 rounded-full', ABS_STATUS_META[status].dot)} />
                        {STATUS_LABEL[status]}
                      </span>
                      <span className="font-semibold">{payload.stats.byStatus[status]}</span>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="flex flex-col gap-4 p-6">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Comparatif</p>
                  <h3 className="font-display text-base font-bold tracking-tight">Absences par groupe</h3>
                </div>
                <ul className="flex flex-col gap-3">
                  {payload.byGroup.map((item, index) => {
                    const max = payload.byGroup[0]?.count ?? 1;
                    return (
                      <li key={item.name}>
                        <div className="mb-1.5 flex items-center justify-between gap-3 text-sm">
                          <span className="truncate font-medium text-foreground">{item.name}</span>
                          <span className="shrink-0 text-muted-foreground">{item.count}</span>
                        </div>
                        <div className="h-2 overflow-hidden rounded-full bg-abs-ink-100">
                          <div
                            className="h-full rounded-full bg-gradient-to-r from-abs-brand-500 to-abs-brand-600 transition-all duration-500"
                            style={{ width: `${(item.count / max) * 100}%`, animationDelay: `${index * 60}ms` }}
                          />
                        </div>
                      </li>
                    );
                  })}
                  {payload.byGroup.length === 0 ? <p className="text-sm text-muted-foreground">Aucune donnée.</p> : null}
                </ul>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="flex flex-col gap-4 p-6">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">3 absences consécutives</p>
                    <h3 className="font-display text-base font-bold tracking-tight">Alertes disciplinaires</h3>
                  </div>
                  <button
                    onClick={() => navigate('/absences/alertes')}
                    className="flex items-center gap-0.5 text-xs font-semibold text-abs-brand-600 hover:text-abs-brand-700"
                  >
                    Voir tout <ChevronRight size={13} />
                  </button>
                </div>
                {payload.activeAlertsPreview.length === 0 ? (
                  <div className="flex flex-col items-center gap-2 py-6 text-center text-muted-foreground">
                    <CheckCircle2 size={26} className="text-abs-success-500" />
                    <p className="text-sm">Aucune alerte active</p>
                  </div>
                ) : (
                  <ul className="flex flex-col gap-2.5">
                    {payload.activeAlertsPreview.map((item) => (
                      <li key={item.learnerId}>
                        <button
                          onClick={() => navigate(`/learners/${item.learnerId}`)}
                          className="flex w-full items-center gap-3 rounded-xl p-2 text-left transition-colors hover:bg-abs-ink-50"
                        >
                          <AbsAvatar name={item.fullName} size="sm" />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold">{item.fullName}</p>
                            <p className="truncate text-xs text-muted-foreground">{item.group ?? 'Sans groupe'}</p>
                          </div>
                          <span className="rounded-full bg-abs-danger-100 px-2.5 py-1 text-xs font-bold text-abs-danger-700">
                            {item.consecutiveCount}/3+
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Historique</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">Évolution des absences</h3>
                </div>
                <div className="flex gap-1 rounded-lg bg-abs-ink-50 p-1">
                  {EVOLUTION_GRANULARITIES.map((item) => (
                    <button
                      key={item.value}
                      type="button"
                      onClick={() => setEvolutionGranularity(item.value)}
                      className={cn(
                        'rounded-md px-3 py-1.5 text-xs font-semibold transition-colors',
                        evolutionGranularity === item.value
                          ? 'bg-white text-abs-brand-700 shadow-sm'
                          : 'text-muted-foreground hover:text-foreground',
                      )}
                    >
                      {item.label}
                    </button>
                  ))}
                </div>
              </div>

              {evolution && evolution.series.some((entry) => entry.total > 0) ? (
                <ResponsiveContainer width="100%" height={300}>
                  <BarChart
                    data={evolution.series.map((entry) => ({
                      ...entry,
                      label: formatPeriodLabel(entry.period, evolution.granularity),
                    }))}
                    margin={{ top: 10, right: 10, left: 0, bottom: 5 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
                    <XAxis
                      dataKey="label"
                      interval={evolutionGranularity === 'day' ? 6 : 0}
                      tick={{ fill: 'var(--color-muted-foreground)', fontSize: 11 }}
                      tickLine={{ stroke: 'var(--color-border)' }}
                    />
                    <YAxis allowDecimals={false} tick={{ fill: 'var(--color-muted-foreground)', fontSize: 11 }} tickLine={{ stroke: 'var(--color-border)' }} />
                    <RechartsTooltip
                      contentStyle={{
                        backgroundColor: 'var(--color-card)',
                        border: '1px solid var(--color-border)',
                        borderRadius: 8,
                        fontFamily: 'var(--font-sans)',
                        fontSize: 12,
                      }}
                      labelFormatter={(label) => label}
                      formatter={(value, name) => [Number(value), STATUS_LABEL[name as AbsenceStatus] ?? String(name)]}
                    />
                    <Legend
                      formatter={(value) => STATUS_LABEL[value as AbsenceStatus] ?? value}
                      wrapperStyle={{ fontSize: 12, fontFamily: 'var(--font-sans)' }}
                    />
                    {STATUS_ORDER.map((status, index) => (
                      <Bar
                        key={status}
                        dataKey={status}
                        stackId="absences"
                        fill={STATUS_COLOR[status]}
                        radius={index === STATUS_ORDER.length - 1 ? [3, 3, 0, 0] : undefined}
                      />
                    ))}
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div className="rounded-md border border-border bg-muted/30 p-10 text-center">
                  <p className="text-sm text-muted-foreground">
                    {evolutionLoading ? 'Chargement du graphique...' : 'Aucune absence sur cette période.'}
                  </p>
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Détections</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">Absences récentes</h3>
                </div>
                <button
                  onClick={() => navigate('/absences')}
                  className="flex items-center gap-0.5 text-xs font-semibold text-abs-brand-600 hover:text-abs-brand-700"
                >
                  Tout voir <ChevronRight size={13} />
                </button>
              </div>

              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Apprenant</TableHead>
                      <TableHead>Session</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Statut</TableHead>
                      <TableHead />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {payload.recent.map((item) => (
                      <TableRow key={item.id} className="cursor-pointer" onClick={() => navigate(`/absences/${item.id}`)}>
                        <TableCell>
                          <div className="flex items-center gap-2.5">
                            <AbsAvatar name={item.learnerFullName} size="sm" />
                            <span className="text-sm font-medium">{item.learnerFullName}</span>
                          </div>
                        </TableCell>
                        <TableCell className="max-w-[200px] truncate text-sm text-muted-foreground">{item.sessionTitle}</TableCell>
                        <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{formatDateTime(item.sessionStartAt)}</TableCell>
                        <TableCell>
                          <AbsTypeChip type={item.type} />
                        </TableCell>
                        <TableCell>
                          <div className="flex items-center gap-1.5">
                            <AbsStatusChip status={item.status} />
                            {item.alertTriggered ? (
                              <span
                                title="Alerte disciplinaire active pour cet apprenant"
                                className="flex h-5 w-5 items-center justify-center rounded-full bg-abs-danger-100 text-abs-danger-600"
                              >
                                <ShieldAlert size={11} />
                              </span>
                            ) : null}
                          </div>
                        </TableCell>
                        <TableCell className="text-right">
                          <ChevronRight size={15} className="ml-auto text-muted-foreground" />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableShell>
              {payload.recent.length === 0 ? <p className="text-sm text-muted-foreground">Aucune absence détectée pour l'instant.</p> : null}
            </CardContent>
          </Card>
        </>
      ) : null}
    </section>
  );
}

const STAT_TONE_CLASS: Record<'brand' | 'success' | 'warning' | 'danger', string> = {
  brand: 'bg-abs-brand-50 text-abs-brand-600',
  success: 'bg-abs-success-50 text-abs-success-600',
  warning: 'bg-abs-warning-50 text-abs-warning-600',
  danger: 'bg-abs-danger-50 text-abs-danger-600',
};

function DashboardStat({
  icon: Icon,
  tone,
  label,
  value,
  hint,
  delay,
}: {
  icon: typeof CalendarX2;
  tone: 'brand' | 'success' | 'warning' | 'danger';
  label: string;
  value: string | number;
  hint: string;
  delay: number;
}) {
  return (
    <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: `${delay}ms` }}>
      <CardContent className="flex items-center gap-4 p-5">
        <span className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-xl', STAT_TONE_CLASS[tone])}>
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
