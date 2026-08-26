import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronDown, ChevronRight, ExternalLink, GraduationCap, Settings2, Users } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { clampPercentage, formatDuration, formatPercentage } from '../lib/format';
import { groupByPromotion, NO_PROMOTION_LABEL } from '../lib/promotion';
import { DASHBOARD_KPIS, DEFAULT_DASHBOARD_KPIS } from '../lib/dashboardKpis';
import { NAV_ITEMS } from '../lib/navItems';
import { DashboardCustomizePanel } from '../components/DashboardCustomizePanel';
import type { DashboardPayload, GroupSummary } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { CountUp } from '@/components/ui/stat';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';

// Trie les promotions par année la plus récente d'abord (à partir du dernier "AAAA" trouvé dans le
// libellé), le bucket "Autres groupes" (aucune promotion identifiable) toujours en dernier.
function comparePromotions(a: string, b: string): number {
  if (a === NO_PROMOTION_LABEL) return 1;
  if (b === NO_PROMOTION_LABEL) return -1;

  const yearOf = (label: string) => Number(label.match(/(\d{4})(?!.*\d{4})/)?.[1] ?? 0);
  return yearOf(b) - yearOf(a);
}

export function DashboardPage() {
  const { token, user } = useAuth();
  const navigate = useNavigate();
  const [dashboard, setDashboard] = useState<DashboardPayload | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [openPromotion, setOpenPromotion] = useState<string | null>(null);
  const [customizeOpen, setCustomizeOpen] = useState(false);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const payload = await apiRequest<DashboardPayload>('/api/dashboard', { token });

        if (!cancelled) {
          setDashboard(payload);
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

  const promotions = useMemo(() => {
    if (!dashboard) return [];

    const buckets = groupByPromotion(dashboard.groups);
    return [...buckets.entries()].sort(([a], [b]) => comparePromotions(a, b));
  }, [dashboard]);

  useEffect(() => {
    if (promotions.length > 0 && openPromotion === null) {
      setOpenPromotion(promotions[0][0]);
    }
  }, [openPromotion, promotions]);

  const promotionsCount = promotions.filter(([label]) => label !== NO_PROMOTION_LABEL).length;
  const preferences = user?.dashboardPreferences ?? null;
  const activeKpiKeys = preferences?.kpis ?? DEFAULT_DASHBOARD_KPIS;
  const shortcuts = preferences?.shortcuts ?? [];

  return (
    <section className="flex flex-col gap-8" data-dashboard-theme={preferences?.theme ?? 'brand'}>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-muted-foreground">
            Tableau de bord &middot; {new Date().toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' })}
          </p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Bonjour, voici votre tableau de bord !</h2>
        </div>
        <Button variant="outline" onClick={() => setCustomizeOpen(true)}>
          <Settings2 size={15} /> Personnaliser
        </Button>
      </div>

      {loading ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, index) => (
            <Card key={index}>
              <CardContent className="p-5">
                <Skeleton className="h-16" />
              </CardContent>
            </Card>
          ))}
        </div>
      ) : null}
      {error ? (
        <Card className="border-destructive/30 bg-destructive/5">
          <CardContent className="p-5 text-sm text-destructive">{error}</CardContent>
        </Card>
      ) : null}

      {dashboard ? (
        <>
          {activeKpiKeys.length > 0 ? (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {activeKpiKeys.map((key, index) => {
                const kpi = DASHBOARD_KPIS.find((item) => item.key === key);
                if (!kpi) return null;
                const value = kpi.getValue({ metrics: dashboard.metrics, promotionsCount });
                const hint =
                  key === 'companiesTutors'
                    ? `${dashboard.metrics.tutorsCount} tuteur${dashboard.metrics.tutorsCount > 1 ? 's' : ''}`
                    : kpi.hint;
                return <StatCard key={key} icon={kpi.icon} label={kpi.label} value={value} hint={hint} delay={index * 80} />;
              })}
            </div>
          ) : null}

          {shortcuts.length > 0 ? (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {shortcuts.map((shortcut) => {
                const target = NAV_ITEMS.find((item) => item.to === shortcut.to);
                const Icon = target?.icon ?? ExternalLink;
                return (
                  <Card
                    key={shortcut.to}
                    className="cursor-pointer transition-all duration-200 hover:-translate-y-1 hover:shadow-soft-hover"
                    onClick={() => navigate(shortcut.to)}
                  >
                    <CardContent className="flex items-center gap-3 p-4">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-brand text-white">
                        <Icon size={16} />
                      </span>
                      <span className="flex-1 text-sm font-semibold">{shortcut.label ?? target?.label ?? shortcut.to}</span>
                      <ChevronRight size={15} className="shrink-0 text-muted-foreground" />
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          ) : null}

          <div className="flex flex-col gap-4">
            <div className="flex items-baseline justify-between">
              <h3 className="font-display text-xl font-bold tracking-tight">Groupes par promotion</h3>
              <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {dashboard.groups.length} au registre
              </span>
            </div>

            <div className="flex flex-col gap-3">
              {promotions.map(([promotion, groups]) => {
                const isOpen = openPromotion === promotion;
                return (
                  <Card key={promotion} className="overflow-hidden">
                    <button
                      type="button"
                      onClick={() => setOpenPromotion(isOpen ? null : promotion)}
                      className="flex w-full items-center justify-between gap-3 p-5 text-left"
                    >
                      <div className="flex items-center gap-3">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-brand text-white">
                          <Users size={16} />
                        </span>
                        <div>
                          <h4 className="font-display text-base font-bold capitalize tracking-tight">{promotion}</h4>
                          <p className="text-xs text-muted-foreground">
                            {groups.length} parcours &middot; {groups.reduce((sum, g) => sum + g.memberCount, 0)} apprenants
                          </p>
                        </div>
                      </div>
                      <ChevronDown size={18} className={cn('shrink-0 text-muted-foreground transition-transform', isOpen && 'rotate-180')} />
                    </button>

                    {isOpen ? (
                      <CardContent className="flex flex-col divide-y divide-border border-t border-border p-0">
                        {groups.map((group) => (
                          <GroupRow key={group.id} group={group} onClick={() => navigate(`/groups/${group.id}`)} />
                        ))}
                      </CardContent>
                    ) : null}
                  </Card>
                );
              })}
            </div>
          </div>
        </>
      ) : null}

      <DashboardCustomizePanel open={customizeOpen} onOpenChange={setCustomizeOpen} preferences={preferences} />
    </section>
  );
}

function GroupRow({ group, onClick }: { group: GroupSummary; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex w-full items-center gap-4 px-5 py-3.5 text-left transition-colors hover:bg-muted/60"
    >
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
        {group.imageUrl ? (
          <img src={group.imageUrl} alt="" className="h-full w-full rounded-lg object-cover" />
        ) : (
          <GraduationCap size={16} />
        )}
      </span>

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold leading-tight">{group.name}</p>
        {group.reference && (
          <p className="mt-0.5 truncate text-xs text-muted-foreground">Réf. {group.reference}</p>
        )}
      </div>

      <div className="hidden shrink-0 items-center gap-5 text-xs text-muted-foreground sm:flex">
        <span className="tabular w-20 text-right">
          <strong className="font-semibold text-foreground">{group.memberCount}</strong> membres
        </span>
        <span className="tabular w-20 text-right">
          <strong className="font-semibold text-foreground">{group.learningPathCount}</strong> parcours
        </span>
        <span className="tabular w-16 text-right">{formatDuration(group.totalTime)}</span>
      </div>

      <div className="flex w-24 shrink-0 items-center gap-2">
        <Progress value={clampPercentage(group.averageProgress)} className="flex-1" />
        <span className="tabular w-9 shrink-0 text-right text-xs font-bold text-primary">
          {formatPercentage(group.averageProgress)}
        </span>
      </div>

      <ChevronRight size={15} className="shrink-0 text-muted-foreground" />
    </button>
  );
}

function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  delay,
}: {
  icon: typeof Users;
  label: string;
  value: string | number;
  hint?: string;
  delay: number;
}) {
  return (
    <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: `${delay}ms` }}>
      <CardContent className="flex items-center gap-4 p-5">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-brand text-white">
          <Icon size={18} />
        </span>
        <div className="min-w-0">
          <p className="mb-1 text-xs font-semibold uppercase tracking-[0.1em] text-muted-foreground">{label}</p>
          <CountUp value={value} className="text-3xl text-primary" />
          {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
      </CardContent>
    </Card>
  );
}
