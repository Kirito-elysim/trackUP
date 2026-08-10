import { useEffect, useMemo, useState } from 'react';
import { Download } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { buildCsv, downloadCsv } from '../lib/csv';
import { formatDateTime, formatDuration, formatPercentage } from '../lib/format';
import type { ExportsPayload } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Select } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { CountUp } from '@/components/ui/stat';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { cn } from '@/lib/utils';

export function ExportsPage() {
  const { token } = useAuth();
  const [learnerId, setLearnerId] = useState('');
  const [payload, setPayload] = useState<ExportsPayload | null>(null);
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

      const params = new URLSearchParams();
      if (learnerId !== '') {
        params.set('learnerId', learnerId);
      }

      try {
        const data = await apiRequest<ExportsPayload>(`/api/exports${params.size > 0 ? `?${params.toString()}` : ''}`, { token });

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
  }, [learnerId, token]);

  const exportCsv = () => {
    if (!selectedLearner) {
      return;
    }

    const headers = [
      'Date',
      'Type',
      'Libelle',
      'Parcours',
      'Formation',
      'Module',
      'Step',
      'Duree',
      'Statut',
      'Signature',
      'Details',
    ];

    const rows = selectedLearner.logs.map((log) => [
      formatDateTime(log.occurredAt),
      log.sourceType,
      log.sourceLabel ?? '',
      log.learningPathTitle ?? '',
      log.trainingTitle ?? '',
      log.moduleTitle ?? '',
      log.stepTitle ?? '',
      formatDuration(log.duration),
      log.status ?? '',
      log.signed === null ? '' : log.signed ? 'Oui' : 'Non',
      log.details ?? '',
    ]);

    const filename = `trackup-export-${selectedLearner.learner.fullName.replaceAll(' ', '-').toLowerCase()}.csv`;
    downloadCsv(filename, buildCsv(headers, rows));
  };

  const selectedLearner = payload?.selectedLearner ?? null;
  const learner = selectedLearner?.learner ?? null;
  const logCounts = useMemo(() => {
    const logs = payload?.selectedLearner?.logs ?? [];

    return logs.reduce(
      (accumulator, log) => {
        if (log.sourceType === 'pathway') accumulator.pathway += 1;
        if (log.sourceType === 'platform') accumulator.platform += 1;
        if (log.sourceType === 'module') accumulator.module += 1;
        if (log.sourceType === 'masterclass') accumulator.masterclass += 1;

        return accumulator;
      },
      { pathway: 0, platform: 0, module: 0, masterclass: 0 },
    );
  }, [payload]);

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Conformité</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Exports</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[38ch] text-sm text-muted-foreground">
            Sélectionne un apprenant et reconstruis un journal exploitable de tout son parcours.
          </p>
          <Chip variant="neutral">Export apprenant</Chip>
        </div>
      </div>

      <Card>
        <CardContent className="p-5">
          <label className="flex flex-col gap-2 sm:max-w-md">
            <span className="text-sm font-semibold">Apprenant</span>
            <Select value={learnerId} onChange={(event) => setLearnerId(event.target.value)}>
              <option value="">Choisir un apprenant</option>
              {(payload?.filters.availableLearners ?? []).map((item) => (
                <option key={item.id} value={item.id}>
                  {item.fullName} · {item.email}
                </option>
              ))}
            </Select>
          </label>
        </CardContent>
      </Card>

      {loading ? <Card><CardContent className="p-5 text-sm text-muted-foreground">Chargement des données d&rsquo;export...</CardContent></Card> : null}
      {error ? <Card className="border-destructive/30 bg-destructive/5"><CardContent className="p-5 text-sm text-destructive">{error}</CardContent></Card> : null}

      {payload ? (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCell label="Apprenants avec temps" value={payload.metrics.learnersReadyCount} hint="Base exportable actuelle" delay={0} />
            <StatCell label="Parcours suivis" value={payload.metrics.learningPathsCount} hint="Parcours détectés localement" delay={80} />
            <StatCell label="Signatures remontées" value={payload.metrics.signedRegistrationsCount} hint="Preuves masterclass" delay={160} />
            <StatCell label="Alertes conformité" value={payload.metrics.sessionsWithoutSignatureCount} hint="Présences sans signature" delay={240} />
          </div>

          {learner ? (
            <>
              <Card>
                <CardContent className="flex flex-col gap-6 p-6">
                  <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                      <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Apprenant sélectionné</p>
                      <h3 className="font-display text-xl font-bold tracking-tight">{learner.fullName}</h3>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {learner.email} · dernière connexion connue {formatDateTime(learner.lastLoginAt)}
                      </p>
                    </div>
                    <Button onClick={exportCsv} variant="outline">
                      <Download size={15} />
                      Export CSV
                    </Button>
                  </div>

                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCell label="Temps plateforme" value={formatDuration(learner.platformTime)} hint="Total consolidé par inscriptions formation" delay={0} muted />
                    <StatCell label="Temps module" value={formatDuration(learner.moduleTime)} hint="Activités steps réellement tracées" delay={60} muted />
                    <StatCell label="Temps masterclass" value={formatDuration(learner.masterclassTime)} hint="Sessions et visios remontées" delay={120} muted />
                    <StatCell
                      label="Parcours / formations"
                      value={`${learner.learningPathCount} / ${learner.trainingCount}`}
                      hint={`${learner.signedAttendanceCount} signature(s) · ${learner.unsignedAttendanceCount} alerte(s)`}
                      delay={180}
                      muted
                    />
                  </div>
                </CardContent>
              </Card>

              <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1.3fr_0.7fr]">
                <Card>
                  <CardContent className="flex flex-col gap-5 p-6">
                    <div>
                      <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Parcours</p>
                      <h3 className="font-display text-lg font-bold tracking-tight">Synthèse du parcours</h3>
                    </div>

                    <TableShell>
                      <Table>
                        <TableHeader>
                          <TableRow>
                            <TableHead>Parcours</TableHead>
                            <TableHead>Inscription</TableHead>
                            <TableHead>Formations</TableHead>
                            <TableHead>Temps plateforme</TableHead>
                            <TableHead>Temps module</TableHead>
                            <TableHead>Temps masterclass</TableHead>
                            <TableHead>Avancement</TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {(selectedLearner?.learningPaths ?? []).map((path) => (
                            <TableRow key={path.learningPathId}>
                              <TableCell>
                                <strong className="block text-sm font-semibold">{path.title}</strong>
                                <span className="text-xs text-muted-foreground">{path.reference ?? 'Référence non renseignée'}</span>
                              </TableCell>
                              <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{formatDateTime(path.subscribedAt)}</TableCell>
                              <TableCell className="tabular text-sm">{path.trainingCount}</TableCell>
                              <TableCell className="tabular whitespace-nowrap text-sm">{formatDuration(path.platformTime)}</TableCell>
                              <TableCell className="tabular whitespace-nowrap text-sm">{formatDuration(path.moduleTime)}</TableCell>
                              <TableCell className="tabular whitespace-nowrap text-sm">{formatDuration(path.masterclassTime)}</TableCell>
                              <TableCell className="tabular text-sm font-semibold">{formatPercentage(path.progress)}</TableCell>
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
                      <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Volumes</p>
                      <h3 className="font-display text-lg font-bold tracking-tight">Répartition des traces</h3>
                    </div>

                    <div className="flex flex-col divide-y divide-border">
                      <VolumeRow label="Inscriptions parcours" hint="Entrées de structure" value={logCounts.pathway} />
                      <VolumeRow label="Traces plateforme" hint="Inscriptions formation consolidées" value={logCounts.platform} />
                      <VolumeRow label="Activités module" hint="Steps et modules e-learning" value={logCounts.module} />
                      <VolumeRow label="Masterclass" hint="Présences et sessions remontées" value={logCounts.masterclass} />
                    </div>
                  </CardContent>
                </Card>
              </div>

              <Card>
                <CardContent className="flex flex-col gap-5 p-6">
                  <div>
                    <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Formations</p>
                    <h3 className="font-display text-lg font-bold tracking-tight">Détail par formation</h3>
                  </div>

                  <TableShell>
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Formation</TableHead>
                          <TableHead>Parcours liés</TableHead>
                          <TableHead>Inscription</TableHead>
                          <TableHead>Temps plateforme</TableHead>
                          <TableHead>Temps module</TableHead>
                          <TableHead>Temps masterclass</TableHead>
                          <TableHead>Avancement</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {(selectedLearner?.trainings ?? []).map((training) => (
                          <TableRow key={training.trainingId}>
                            <TableCell>
                              <strong className="block text-sm font-semibold">{training.title}</strong>
                              <span className="text-xs text-muted-foreground">{training.state ?? 'État non renseigné'}</span>
                            </TableCell>
                            <TableCell className="text-sm text-muted-foreground">{training.learningPathTitles.join(', ') || 'Aucun parcours lié'}</TableCell>
                            <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{formatDateTime(training.subscribedAt)}</TableCell>
                            <TableCell className="tabular whitespace-nowrap text-sm">{formatDuration(training.platformTime)}</TableCell>
                            <TableCell className="tabular whitespace-nowrap text-sm">{formatDuration(training.moduleTime)}</TableCell>
                            <TableCell className="tabular whitespace-nowrap text-sm">{formatDuration(training.masterclassTime)}</TableCell>
                            <TableCell className="tabular text-sm font-semibold">{formatPercentage(training.progress)}</TableCell>
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
                    <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Journal</p>
                    <h3 className="font-display text-lg font-bold tracking-tight">Logs chronologiques du parcours</h3>
                  </div>

                  <TableShell>
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Date</TableHead>
                          <TableHead>Type</TableHead>
                          <TableHead>Libellé</TableHead>
                          <TableHead>Parcours</TableHead>
                          <TableHead>Formation</TableHead>
                          <TableHead>Module / Step</TableHead>
                          <TableHead>Temps</TableHead>
                          <TableHead>Statut</TableHead>
                          <TableHead>Signature</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {(selectedLearner?.logs ?? []).map((log, index) => (
                          <TableRow key={`${log.sourceType}-${log.occurredAt}-${index}`}>
                            <TableCell className="whitespace-nowrap text-sm text-muted-foreground">{formatDateTime(log.occurredAt)}</TableCell>
                            <TableCell><Chip variant="neutral">{renderSourceType(log.sourceType)}</Chip></TableCell>
                            <TableCell>
                              <strong className="block text-sm font-semibold">{log.sourceLabel ?? 'Activité'}</strong>
                              <span className="text-xs text-muted-foreground">{log.details ?? 'Aucun détail'}</span>
                            </TableCell>
                            <TableCell className="text-sm text-muted-foreground">{log.learningPathTitle ?? 'Non rattaché'}</TableCell>
                            <TableCell className="text-sm text-muted-foreground">{log.trainingTitle ?? 'Non rattachée'}</TableCell>
                            <TableCell className="text-sm text-muted-foreground">
                              {[log.moduleTitle, log.stepTitle].filter(Boolean).join(' · ') || 'n/a'}
                            </TableCell>
                            <TableCell className="tabular whitespace-nowrap text-sm">{formatDuration(log.duration)}</TableCell>
                            <TableCell className="text-sm text-muted-foreground">{log.status ?? 'Non renseigné'}</TableCell>
                            <TableCell className="text-sm">{log.signed === null ? '-' : log.signed ? 'Oui' : 'Non'}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </TableShell>
                </CardContent>
              </Card>
            </>
          ) : (
            <Card>
              <CardContent className="p-6 text-sm text-muted-foreground">
                Choisis un apprenant pour charger son dossier exportable : parcours, formations, temps consolidés et
                journal de traces.
              </CardContent>
            </Card>
          )}
        </>
      ) : null}
    </section>
  );
}

function StatCell({
  label,
  value,
  hint,
  delay,
  muted,
}: {
  label: string;
  value: string | number;
  hint: string;
  delay: number;
  muted?: boolean;
}) {
  return (
    <Card
      className={cn('animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover', muted && 'bg-muted/40 shadow-none')}
      style={{ animationDelay: `${delay}ms` }}
    >
      <CardContent className="flex flex-col gap-1.5 p-5">
        <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p>
        <CountUp value={value} className="text-xl" />
        <p className="text-xs text-muted-foreground">{hint}</p>
      </CardContent>
    </Card>
  );
}

function VolumeRow({ label, hint, value }: { label: string; hint: string; value: number }) {
  return (
    <div className="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
      <div>
        <strong className="text-sm font-semibold">{label}</strong>
        <p className="text-xs text-muted-foreground">{hint}</p>
      </div>
      <span className="tabular text-sm font-semibold">{value}</span>
    </div>
  );
}

function renderSourceType(sourceType: string) {
  switch (sourceType) {
    case 'pathway':
      return 'Parcours';
    case 'platform':
      return 'Plateforme';
    case 'module':
      return 'Module';
    case 'masterclass':
      return 'Masterclass';
    default:
      return sourceType;
  }
}
