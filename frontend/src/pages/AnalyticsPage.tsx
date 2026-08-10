import { useEffect, useMemo, useState } from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { buildCsv, downloadCsv } from '../lib/csv';
import { formatDateTime, formatDuration, formatPercentage, minutesToHours } from '../lib/format';
import { compareValues } from '../lib/sort';
import type { AnalyticsPayload } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/ui/chip';
import { CountUp } from '@/components/ui/stat';
import { SortableHead, Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';

const PERIOD_OPTIONS = [
  { value: 'day', label: 'Jour' },
  { value: '7d', label: '7 jours' },
  { value: '30d', label: '30 jours' },
  { value: 'month', label: 'Mois en cours' },
  { value: 'year', label: 'Année' },
  { value: 'custom', label: 'Personnalisée' },
];

export function AnalyticsPage() {
  const { token } = useAuth();
  const [period, setPeriod] = useState('30d');
  const [learningPathId, setLearningPathId] = useState('');
  const [learnerId, setLearnerId] = useState('');
  const [startAt, setStartAt] = useState('');
  const [endAt, setEndAt] = useState('');
  const [pathSort, setPathSort] = useState<'title' | 'totalTime' | 'timeProgressPercent' | 'averageProgress'>('totalTime');
  const [pathDirection, setPathDirection] = useState<'asc' | 'desc'>('desc');
  const [learnerSort, setLearnerSort] = useState<
    'fullName' | 'learningPathTitle' | 'totalTime' | 'trainingProgressPercent' | 'learningPathProgressPercent' | 'timeProgressPercent' | 'lastActivityAt'
  >('totalTime');
  const [learnerDirection, setLearnerDirection] = useState<'asc' | 'desc'>('desc');
  const [analytics, setAnalytics] = useState<AnalyticsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      const params = new URLSearchParams({ period });
      if (learningPathId !== '') {
        params.set('learningPathId', learningPathId);
      }
      if (learnerId !== '') {
        params.set('learnerId', learnerId);
      }
      if (period === 'custom') {
        if (startAt !== '') {
          params.set('startAt', startAt);
        }
        if (endAt !== '') {
          params.set('endAt', endAt);
        }
      }

      try {
        const payload = await apiRequest<AnalyticsPayload>(`/api/analytics?${params.toString()}`, { token });
        if (!cancelled) {
          setAnalytics(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement des analytics impossible.');
          setAnalytics(null);
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
  }, [endAt, learnerId, learningPathId, period, startAt, token]);

  const cards = analytics
    ? [
        {
          title: 'Temps tracé',
          value: formatDuration(analytics.metrics.totalTrackedTime),
          hint: `${analytics.metrics.activityCount} activités comptabilisées`,
        },
        {
          title: 'Temps e-learning',
          value: formatDuration(analytics.metrics.elearningTrackedTime),
          hint: 'Temps issu des steps et modules',
        },
        {
          title: 'Temps masterclass',
          value: formatDuration(analytics.metrics.masterclassTrackedTime),
          hint: 'Temps issu des sessions et masterclass',
        },
        {
          title: 'Apprenants actifs',
          value: analytics.metrics.activeLearnersCount.toString(),
          hint: `${analytics.metrics.learningPathsCount} parcours concernés`,
        },
        {
          title: 'Progression moyenne',
          value: formatPercentage(analytics.metrics.averageProgress),
          hint: 'Snapshot courant des inscriptions parcours',
        },
        {
          title: 'Dernière synchro',
          value: formatDateTime(analytics?.lastSyncAt),
          hint: 'Données locales consolidées',
        },
      ]
    : [];

  const comparisonCards = analytics
    ? [
        {
          title: 'Temps total',
          metric: analytics.comparison.totalTrackedTime,
          formatter: formatDuration,
        },
        {
          title: 'E-learning',
          metric: analytics.comparison.elearningTrackedTime,
          formatter: formatDuration,
        },
        {
          title: 'Masterclass',
          metric: analytics.comparison.masterclassTrackedTime,
          formatter: formatDuration,
        },
        {
          title: 'Apprenants actifs',
          metric: analytics.comparison.activeLearnersCount,
          formatter: (value: number) => String(Math.round(value)),
        },
      ]
    : [];

  const sortedLearningPaths = useMemo(() => {
    const rows = [...(analytics?.topLearningPaths ?? [])];
    rows.sort((left, right) => compareValues(left[pathSort], right[pathSort], pathDirection));
    return rows;
  }, [analytics?.topLearningPaths, pathDirection, pathSort]);

  const sortedLearnerRows = useMemo(() => {
    const rows = [...(analytics?.learnerPathRows ?? [])];
    rows.sort((left, right) => compareValues(left[learnerSort], right[learnerSort], learnerDirection));
    return rows;
  }, [analytics?.learnerPathRows, learnerDirection, learnerSort]);

  const selectedLearner = useMemo(
    () => sortedLearnerRows.find((row) => String(row.learnerId) === learnerId && String(row.learningPathId) === learningPathId) ?? null,
    [learnerId, learningPathId, sortedLearnerRows],
  );

  const handlePathSort = (key: typeof pathSort) => {
    if (pathSort === key) {
      setPathDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
      return;
    }

    setPathSort(key);
    setPathDirection(key === 'title' ? 'asc' : 'desc');
  };

  const handleLearnerSort = (key: typeof learnerSort) => {
    if (learnerSort === key) {
      setLearnerDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
      return;
    }

    setLearnerSort(key);
    setLearnerDirection(key === 'fullName' || key === 'learningPathTitle' ? 'asc' : 'desc');
  };

  const exportCsv = () => {
    if (!analytics) {
      return;
    }

    const headers = [
      'Apprenant',
      'Email',
      'Parcours',
      'Temps periode',
      'Temps e-learning',
      'Temps masterclass',
      '% avancement formation',
      '% avancement parcours',
      '% temps parcours',
      'Derniere activite',
      'Derniere connexion',
    ];

    const rows = sortedLearnerRows.map((row) => [
      row.fullName,
      row.email ?? '',
      row.learningPathTitle,
      formatDuration(row.totalTime),
      formatDuration(row.elearningTime),
      formatDuration(row.masterclassTime),
      formatPercentage(row.trainingProgressPercent),
      formatPercentage(row.learningPathProgressPercent),
      formatPercentage(row.timeProgressPercent),
      formatDateTime(row.lastActivityAt),
      formatDateTime(row.lastLoginAt),
    ]);

    downloadCsv(`trackup-analytics-${analytics.filters.period}.csv`, buildCsv(headers, rows));
  };

  const focusLearnerRow = (nextLearningPathId: number, nextLearnerId: number) => {
    if (learningPathId === String(nextLearningPathId) && learnerId === String(nextLearnerId)) {
      setLearnerId('');
      return;
    }

    setLearningPathId(String(nextLearningPathId));
    setLearnerId(String(nextLearnerId));
  };

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Pilotage</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Analytics</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[42ch] text-sm text-muted-foreground">
            Lecture rapide de l&rsquo;activité, du temps tracé et de la progression par période.
          </p>
          <div className="flex flex-wrap items-center justify-end gap-2.5">
            <Chip variant="neutral">
              {analytics ? `${analytics.filters.period} · ${formatDateTime(analytics.filters.endAt)}` : 'Chargement'}
            </Chip>
            <Button variant="outline" size="sm" onClick={exportCsv}>
              Export CSV
            </Button>
          </div>
        </div>
      </div>

      <Card>
        <CardContent className="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2 lg:grid-cols-4">
          <label className="flex flex-col gap-2">
            <span className="text-sm font-semibold">Période</span>
            <Select value={period} onChange={(event) => setPeriod(event.target.value)}>
              {PERIOD_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </label>

          <label className="flex flex-col gap-2">
            <span className="text-sm font-semibold">Parcours</span>
            <Select
              value={learningPathId}
              onChange={(event) => {
                setLearningPathId(event.target.value);
                setLearnerId('');
              }}
            >
              <option value="">Tous les parcours</option>
              {(analytics?.filters.availableLearningPaths ?? []).map((item) => (
                <option key={item.id} value={item.id}>
                  {item.title}
                </option>
              ))}
            </Select>
          </label>

          <label className="flex flex-col gap-2">
            <span className="text-sm font-semibold">Apprenant</span>
            <Select value={learnerId} onChange={(event) => setLearnerId(event.target.value)}>
              <option value="">Tous les apprenants</option>
              {(analytics?.filters.availableLearners ?? []).map((item) => (
                <option key={item.id} value={item.id}>
                  {item.fullName}
                </option>
              ))}
            </Select>
          </label>

          {period === 'custom' ? (
            <>
              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Début</span>
                <Input type="date" value={startAt} onChange={(event) => setStartAt(event.target.value)} />
              </label>
              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Fin</span>
                <Input type="date" value={endAt} onChange={(event) => setEndAt(event.target.value)} />
              </label>
            </>
          ) : null}
        </CardContent>
      </Card>

      {loading ? <Card><CardContent className="p-5 text-sm text-muted-foreground">Chargement des analytics...</CardContent></Card> : null}
      {error ? <Card className="border-destructive/30 bg-destructive/5"><CardContent className="p-5 text-sm text-destructive">{error}</CardContent></Card> : null}

      {analytics ? (
        <>
          <Card className="overflow-hidden border-0 bg-gradient-brand text-white">
            <CardContent className="flex flex-col gap-4 p-8">
              <Chip variant="onGradient" className="w-fit">Lecture tabulaire</Chip>
              <h3 className="font-display text-2xl font-extrabold leading-tight tracking-tight">
                Un suivi simple à lire, centré sur le temps et l&rsquo;avancement.
              </h3>
              <p className="max-w-[70ch] text-sm text-white/65">
                Cette vue privilégie les tableaux pour aller vite : temps sur la période, progression formation et
                pourcentage d&rsquo;avancement du temps dans le parcours.
              </p>
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {cards.map((card, index) => (
              <Card
                className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover"
                key={card.title}
                style={{ animationDelay: `${index * 60}ms` }}
              >
                <CardContent className="flex flex-col gap-1.5 p-5">
                  <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{card.title}</p>
                  <CountUp value={card.value} className="text-xl" />
                  <p className="text-xs text-muted-foreground">{card.hint}</p>
                </CardContent>
              </Card>
            ))}
          </div>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Comparaison</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">Écart avec la période précédente</h3>
                </div>
                <Chip variant="neutral">
                  {formatDateTime(analytics.filters.previousStartAt)} → {formatDateTime(analytics.filters.previousEndAt)}
                </Chip>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {comparisonCards.map((card) => (
                  <div className="flex flex-col gap-1.5 rounded-xl bg-muted/50 p-4" key={card.title}>
                    <p className="text-[0.64rem] font-semibold uppercase tracking-wide text-muted-foreground">{card.title}</p>
                    <strong className="tabular text-lg font-bold">{card.formatter(card.metric.current)}</strong>
                    <p className="text-xs text-muted-foreground">
                      Avant : {card.formatter(card.metric.previous)} · {formatSignedDelta(card.metric.delta, card.formatter)} (
                      {formatSignedPercent(card.metric.percentDelta)})
                    </p>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Période</p>
                <h3 className="font-display text-lg font-bold tracking-tight">Temps tracé par tranche</h3>
              </div>

              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Période</TableHead>
                      <TableHead>Temps total</TableHead>
                      <TableHead>E-learning</TableHead>
                      <TableHead>Masterclass</TableHead>
                      <TableHead>Activités</TableHead>
                      <TableHead>Apprenants actifs</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {analytics.timeSeries.map((item) => (
                      <TableRow key={item.bucketKey}>
                        <TableCell className="text-sm">{item.label}</TableCell>
                        <TableCell className="tabular text-sm font-semibold">{formatDuration(item.totalTime)}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(item.elearningTime)}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(item.masterclassTime)}</TableCell>
                        <TableCell className="tabular text-sm">{item.activityCount}</TableCell>
                        <TableCell className="tabular text-sm">{item.activeLearnersCount}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableShell>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-6 p-6">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Évolution</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">Temps d&rsquo;apprentissage par jour</h3>
                </div>
                <Chip variant="neutral">{analytics.timeSeries.length} période(s)</Chip>
              </div>

              {analytics.timeSeries.length > 0 ? (
                <div className="rounded-md border border-border bg-muted/30 p-4">
                  <ResponsiveContainer width="100%" height={380}>
                    <BarChart
                      data={analytics.timeSeries.map((item) => ({
                        label: item.label,
                        'E-learning': Number(minutesToHours(item.elearningTime).toFixed(1)),
                        Masterclass: Number(minutesToHours(item.masterclassTime).toFixed(1)),
                      }))}
                      margin={{ top: 10, right: 20, left: 10, bottom: 55 }}
                    >
                      <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
                      <XAxis
                        dataKey="label"
                        angle={-45}
                        textAnchor="end"
                        height={75}
                        tick={{ fill: 'var(--color-muted-foreground)', fontSize: 11, fontFamily: 'var(--)' }}
                        tickLine={{ stroke: 'var(--color-border)' }}
                      />
                      <YAxis
                        label={{ value: 'Heures', angle: -90, position: 'insideLeft', style: { fill: 'var(--color-muted-foreground)' } }}
                        tick={{ fill: 'var(--color-muted-foreground)', fontSize: 11, fontFamily: 'var(--)' }}
                        tickLine={{ stroke: 'var(--color-border)' }}
                      />
                      <Tooltip
                        contentStyle={{
                          backgroundColor: 'var(--color-card)',
                          border: '1px solid var(--color-border)',
                          borderRadius: 4,
                          fontFamily: 'var(--font-sans)',
                          fontSize: 13,
                        }}
                        formatter={(value) => `${value}h`}
                      />
                      <Legend wrapperStyle={{ paddingTop: 20, fontFamily: 'var(--font-sans)', fontSize: 13 }} />
                      <Bar dataKey="E-learning" stackId="a" fill="var(--color-board)" radius={[0, 0, 0, 0]} />
                      <Bar dataKey="Masterclass" stackId="a" fill="var(--color-primary)" radius={[2, 2, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              ) : (
                <div className="rounded-md border border-border bg-muted/30 p-12 text-center">
                  <p className="text-sm text-muted-foreground">Aucune donnée disponible pour la période sélectionnée</p>
                </div>
              )}

              <div className="grid grid-cols-1 gap-4 border-t border-border pt-5 sm:grid-cols-3">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Temps total sur la période</p>
                  <CountUp value={formatDuration(analytics.metrics.totalTrackedTime)} className="text-xl" />
                </div>
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Moyenne par jour</p>
                  <CountUp
                    value={formatDuration(
                      analytics.timeSeries.length > 0 ? Math.round(analytics.metrics.totalTrackedTime / analytics.timeSeries.length) : 0,
                    )}
                    className="text-xl"
                  />
                </div>
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Pic d&rsquo;activité</p>
                  <CountUp
                    value={
                      analytics.timeSeries.length > 0
                        ? analytics.timeSeries.reduce((max, curr) => (curr.totalTime > max.totalTime ? curr : max)).label
                        : 'N/A'
                    }
                    className="text-xl"
                  />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Parcours</p>
                <h3 className="font-display text-lg font-bold tracking-tight">Synthèse par parcours</h3>
              </div>

              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <SortableHead active={pathSort === 'title'} direction={pathDirection} onClick={() => handlePathSort('title')}>Parcours</SortableHead>
                      <TableHead>Apprenants</TableHead>
                      <SortableHead active={pathSort === 'totalTime'} direction={pathDirection} onClick={() => handlePathSort('totalTime')}>Temps période</SortableHead>
                      <TableHead>E-learning</TableHead>
                      <TableHead>Masterclass</TableHead>
                      <TableHead>Durée cible</TableHead>
                      <SortableHead active={pathSort === 'timeProgressPercent'} direction={pathDirection} onClick={() => handlePathSort('timeProgressPercent')}>% temps parcours</SortableHead>
                      <SortableHead active={pathSort === 'averageProgress'} direction={pathDirection} onClick={() => handlePathSort('averageProgress')}>% avancement formation</SortableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {sortedLearningPaths.map((item) => (
                      <TableRow key={item.id}>
                        <TableCell className="text-sm font-semibold">{item.title}</TableCell>
                        <TableCell className="tabular text-sm">{item.learnerCount}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(item.totalTime)}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(item.elearningTime)}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(item.masterclassTime)}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(item.targetDuration)}</TableCell>
                        <TableCell className="tabular text-sm">{formatPercentage(item.timeProgressPercent)}</TableCell>
                        <TableCell className="tabular text-sm">{formatPercentage(item.averageProgress)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableShell>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Formations</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">
                    {selectedLearner ? `Détail des formations de ${selectedLearner.fullName}` : 'Détail par formation'}
                  </h3>
                </div>
                <Chip variant="neutral">{analytics.trainingRows.length} formation(s) dans le périmètre</Chip>
              </div>

              {selectedLearner ? (
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-md border border-primary/25 bg-primary/5 px-4 py-3.5">
                  <div>
                    <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Focus apprenant</p>
                    <strong className="text-sm font-semibold">{selectedLearner.fullName}</strong>
                    <p className="text-sm text-muted-foreground">
                      {selectedLearner.learningPathTitle} · {formatDuration(selectedLearner.totalTime)} sur la période
                    </p>
                  </div>
                  <Button variant="outline" size="sm" onClick={() => setLearnerId('')}>
                    Retirer le focus
                  </Button>
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">
                  Clique une ligne du tableau apprenant pour focaliser le détail formation sur un apprenant précis.
                </p>
              )}

              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Formation</TableHead>
                      <TableHead>Apprenants</TableHead>
                      <TableHead>Temps total</TableHead>
                      <TableHead>E-learning</TableHead>
                      <TableHead>Masterclass</TableHead>
                      <TableHead>Durée cible</TableHead>
                      <TableHead>% temps formation</TableHead>
                      <TableHead>% avancement moyen</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {analytics.trainingRows.map((row) => (
                      <TableRow key={row.trainingId}>
                        <TableCell className="text-sm font-semibold">{row.title}</TableCell>
                        <TableCell className="tabular text-sm">{row.learnerCount}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(row.totalTime)}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(row.elearningTime)}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(row.masterclassTime)}</TableCell>
                        <TableCell className="tabular text-sm">{formatDuration(row.targetDuration)}</TableCell>
                        <TableCell className="tabular text-sm">{formatPercentage(row.timeProgressPercent)}</TableCell>
                        <TableCell className="tabular text-sm">{formatPercentage(row.averageProgress)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableShell>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Détail apprenants</p>
                <h3 className="font-display text-lg font-bold tracking-tight">Temps et avancement par parcours</h3>
              </div>

              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <SortableHead active={learnerSort === 'fullName'} direction={learnerDirection} onClick={() => handleLearnerSort('fullName')}>Apprenant</SortableHead>
                      <SortableHead active={learnerSort === 'learningPathTitle'} direction={learnerDirection} onClick={() => handleLearnerSort('learningPathTitle')}>Parcours</SortableHead>
                      <SortableHead active={learnerSort === 'totalTime'} direction={learnerDirection} onClick={() => handleLearnerSort('totalTime')}>Temps période</SortableHead>
                      <TableHead>E-learning</TableHead>
                      <TableHead>Masterclass</TableHead>
                      <SortableHead active={learnerSort === 'trainingProgressPercent'} direction={learnerDirection} onClick={() => handleLearnerSort('trainingProgressPercent')}>% avancement formation</SortableHead>
                      <SortableHead active={learnerSort === 'learningPathProgressPercent'} direction={learnerDirection} onClick={() => handleLearnerSort('learningPathProgressPercent')}>% avancement parcours</SortableHead>
                      <SortableHead active={learnerSort === 'timeProgressPercent'} direction={learnerDirection} onClick={() => handleLearnerSort('timeProgressPercent')}>% temps parcours</SortableHead>
                      <SortableHead active={learnerSort === 'lastActivityAt'} direction={learnerDirection} onClick={() => handleLearnerSort('lastActivityAt')}>Dernière activité</SortableHead>
                      <TableHead>Dernière connexion</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {sortedLearnerRows.map((row) => {
                      const active = learningPathId === String(row.learningPathId) && learnerId === String(row.learnerId);

                      return (
                        <TableRow
                          className={active ? 'cursor-pointer bg-primary/5' : 'cursor-pointer'}
                          key={`${row.learnerId}-${row.learningPathId}`}
                          onClick={() => focusLearnerRow(row.learningPathId, row.learnerId)}
                          onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                              event.preventDefault();
                              focusLearnerRow(row.learningPathId, row.learnerId);
                            }
                          }}
                          role="button"
                          tabIndex={0}
                        >
                          <TableCell>
                            <strong className="block text-sm font-semibold">{row.fullName}</strong>
                            <span className="text-xs text-muted-foreground">{row.email ?? 'Email indisponible'}</span>
                          </TableCell>
                          <TableCell className="text-sm">{row.learningPathTitle}</TableCell>
                          <TableCell className="tabular text-sm">{formatDuration(row.totalTime)}</TableCell>
                          <TableCell className="tabular text-sm">{formatDuration(row.elearningTime)}</TableCell>
                          <TableCell className="tabular text-sm">{formatDuration(row.masterclassTime)}</TableCell>
                          <TableCell className="tabular text-sm">{formatPercentage(row.trainingProgressPercent)}</TableCell>
                          <TableCell className="tabular text-sm">{formatPercentage(row.learningPathProgressPercent)}</TableCell>
                          <TableCell className="tabular text-sm">{formatPercentage(row.timeProgressPercent)}</TableCell>
                          <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{formatDateTime(row.lastActivityAt)}</TableCell>
                          <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{formatDateTime(row.lastLoginAt)}</TableCell>
                        </TableRow>
                      );
                    })}
                  </TableBody>
                </Table>
              </TableShell>
            </CardContent>
          </Card>
        </>
      ) : null}
    </section>
  );
}

function formatSignedPercent(value: number) {
  const rounded = value.toFixed(2);
  return value > 0 ? `+${rounded}%` : `${rounded}%`;
}

function formatSignedDelta(value: number, formatter: (value: number) => string) {
  if (value === 0) {
    return formatter(0);
  }

  const formatted = formatter(Math.abs(value));

  return value > 0 ? `+${formatted}` : `-${formatted}`;
}
