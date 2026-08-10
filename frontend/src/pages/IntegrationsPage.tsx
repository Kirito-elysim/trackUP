import { useEffect, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { formatDateTime } from '../lib/format';
import type { IntegrationsPayload } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Chip } from '@/components/ui/chip';
import { CountUp } from '@/components/ui/stat';

export function IntegrationsPage() {
  const { token } = useAuth();
  const [payload, setPayload] = useState<IntegrationsPayload | null>(null);
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

      try {
        const data = await apiRequest<IntegrationsPayload>('/api/integrations', { token });

        if (!cancelled) {
          setPayload(data);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
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
  }, [token]);

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Synchronisation</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Intégrations</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[38ch] text-sm text-muted-foreground">
            État local de la connexion Rise Up, fraîcheur des jeux de données et commandes utiles.
          </p>
          <Chip variant="neutral">Lecture seule</Chip>
        </div>
      </div>

      {loading ? <Card><CardContent className="p-5 text-sm text-muted-foreground">Chargement des intégrations...</CardContent></Card> : null}
      {error ? <Card className="border-destructive/30 bg-destructive/5"><CardContent className="p-5 text-sm text-destructive">{error}</CardContent></Card> : null}

      {payload ? (
        <>
          <Card className="overflow-hidden border-0 bg-gradient-brand text-white">
            <CardContent className="grid grid-cols-1 gap-8 p-8 lg:grid-cols-[1.4fr_0.8fr]">
              <div className="flex flex-col gap-4">
                <Chip variant="onGradient" className="w-fit">Rise Up API</Chip>
                <h3 className="font-display text-2xl font-extrabold leading-tight tracking-tight">
                  Synchronisation locale pilotée, sans écriture côté LMS.
                </h3>
                <p className="max-w-[60ch] text-sm text-white/65">
                  TrackUp ne pousse rien dans Rise Up. Cette vue montre uniquement la santé du lien, la fraîcheur des
                  données importées et les commandes Symfony disponibles pour relancer une sync.
                </p>
              </div>

              <div className="grid gap-3">
                <div className="rounded-xl border border-white/10 bg-white/[0.06] p-4">
                  <span className="text-xs uppercase tracking-wide text-white/70">Mode</span>
                  <strong className="mt-1 block text-lg font-bold">{payload.connection.mode}</strong>
                </div>
                <div className="rounded-xl border border-white/10 bg-white/[0.06] p-4">
                  <span className="text-xs uppercase tracking-wide text-white/70">Dernière synchro</span>
                  <strong className="mt-1 block text-lg font-bold">{formatDateTime(payload.connection.lastSyncAt)}</strong>
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard label="Jeux de données" value={payload.metrics.datasetsCount} hint="Blocs fonctionnels suivis localement" delay={0} />
            <StatCard label="Blocs synchronisés" value={payload.metrics.syncedDatasetsCount} hint="Sources avec données effectivement présentes" delay={80} />
            <StatCard label="Lignes locales" value={payload.metrics.totalRows} hint="Total cumulé des lignes actuellement stockées" delay={160} />
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <Card>
              <CardContent className="flex flex-col gap-5 p-6">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Jeux de données</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">Fraîcheur par domaine</h3>
                </div>

                <div className="flex flex-col divide-y divide-border">
                  {payload.datasets.map((dataset) => (
                    <div className="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0" key={dataset.table}>
                      <div>
                        <strong className="text-sm font-semibold">{dataset.label}</strong>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                          Table principale <code className="text-xs">{dataset.table}</code>
                          {dataset.secondaryCount !== null ? ` · complément ${dataset.secondaryCount}` : ''}
                        </p>
                      </div>
                      <div className="flex flex-col items-end gap-0.5 text-right">
                        <span className="tabular text-sm font-semibold">{dataset.primaryCount} lignes</span>
                        <span className="text-xs text-muted-foreground">{formatDateTime(dataset.lastSyncAt)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="flex flex-col gap-5 p-6">
                <div>
                  <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Commandes</p>
                  <h3 className="font-display text-lg font-bold tracking-tight">Relance manuelle</h3>
                </div>

                <div className="flex flex-col gap-2.5">
                  {payload.commands.map((command) => (
                    <code key={command} className="rounded-md border border-border bg-muted/50 px-3 py-2.5 text-xs">
                      {command}
                    </code>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Connexion</p>
                <h3 className="font-display text-lg font-bold tracking-tight">Paramètres exposés</h3>
              </div>

              <div className="flex flex-col divide-y divide-border">
                <div className="flex items-start justify-between gap-4 py-3 first:pt-0">
                  <div>
                    <strong className="text-sm font-semibold">Provider</strong>
                    <p className="mt-0.5 text-sm text-muted-foreground">{payload.connection.provider}</p>
                  </div>
                  <div className="flex flex-col items-end gap-0.5 text-right">
                    <span className="text-sm font-semibold">{payload.connection.health}</span>
                    <span className="text-xs text-muted-foreground">Santé API locale</span>
                  </div>
                </div>

                <div className="flex items-start justify-between gap-4 py-3 last:pb-0">
                  <div>
                    <strong className="text-sm font-semibold">Base URL</strong>
                    <p className="mt-0.5 text-sm text-muted-foreground">{payload.connection.apiBaseUrl}</p>
                  </div>
                  <div className="flex flex-col items-end gap-0.5 text-right">
                    <span className="text-sm font-semibold">{payload.connection.mode}</span>
                    <span className="text-xs text-muted-foreground">Mode d&rsquo;accès</span>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </>
      ) : null}
    </section>
  );
}

function StatCard({ label, value, hint, delay }: { label: string; value: string | number; hint: string; delay: number }) {
  return (
    <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: `${delay}ms` }}>
      <CardContent className="flex flex-col gap-1.5 p-5">
        <p className="text-[0.68rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p>
        <CountUp value={value} className="text-2xl" />
        <p className="text-xs text-muted-foreground">{hint}</p>
      </CardContent>
    </Card>
  );
}
