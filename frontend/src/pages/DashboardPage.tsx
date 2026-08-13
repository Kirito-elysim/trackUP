import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { BookOpen, Clock, Route, Users } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { clampPercentage, formatDuration, formatPercentage } from '../lib/format';
import type { DashboardPayload } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { CountUp } from '@/components/ui/stat';
import { Progress } from '@/components/ui/progress';

export function DashboardPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [dashboard, setDashboard] = useState<DashboardPayload | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

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

  return (
    <section className="flex flex-col gap-8">
      <div>
        <p className="mb-1.5 text-xs font-semibold uppercase tracking-[0.16em] text-muted-foreground">
          Tableau de bord &middot; {new Date().toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' })}
        </p>
        <h2 className="font-display text-3xl font-extrabold tracking-tight">Bonjour, voici votre tableau de bord !</h2>
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
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard icon={BookOpen} label="Formations" value={dashboard.metrics.trainingsCount} delay={0} />
            <StatCard icon={Route} label="Parcours" value={dashboard.metrics.learningPathsCount} delay={80} />
            <StatCard icon={Users} label="Apprenants" value={dashboard.metrics.learnersCount} delay={160} />
            <StatCard
              icon={Clock}
              label="Temps de formation"
              value={formatDuration(dashboard.metrics.totalYearTime)}
              delay={240}
            />
          </div>

          <div className="flex flex-col gap-4">
            <div className="flex items-baseline justify-between">
              <h3 className="font-display text-xl font-bold tracking-tight">Groupes</h3>
              <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {dashboard.groups.length} au registre
              </span>
            </div>

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {dashboard.groups.map((group, index) => (
                <Card
                  key={group.id}
                  className="animate-rise-in cursor-pointer overflow-hidden hover:-translate-y-1 hover:shadow-soft-hover"
                  style={{ animationDelay: `${index * 60}ms` }}
                  onClick={() => navigate(`/groups/${group.id}`)}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      navigate(`/groups/${group.id}`);
                    }
                  }}
                >
                  <div className="flex h-40 w-full items-center justify-center bg-muted">
                    {group.imageUrl ? (
                      <img src={group.imageUrl} alt={group.name} className="h-full w-full object-cover" />
                    ) : (
                      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-gradient-brand text-white">
                        <Users size={24} />
                      </span>
                    )}
                  </div>
                  <CardContent className="flex flex-col gap-5 p-6">
                    <div>
                      <h4 className="font-display text-base font-bold leading-tight tracking-tight">{group.name}</h4>
                      {group.reference && (
                        <p className="mt-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                          Réf. {group.reference}
                        </p>
                      )}
                    </div>
                    <dl className="flex items-center justify-between border-y border-border py-3 text-sm">
                      <div>
                        <dt className="text-[0.62rem] uppercase tracking-wide text-muted-foreground">Membres</dt>
                        <dd className="tabular mt-1 font-bold text-primary">{group.memberCount}</dd>
                      </div>
                      <div>
                        <dt className="text-[0.62rem] uppercase tracking-wide text-muted-foreground">Parcours</dt>
                        <dd className="tabular mt-1 font-bold text-primary">{group.learningPathCount}</dd>
                      </div>
                      <div>
                        <dt className="text-[0.62rem] uppercase tracking-wide text-muted-foreground">Temps</dt>
                        <dd className="tabular mt-1 font-bold text-primary">{formatDuration(group.totalTime)}</dd>
                      </div>
                    </dl>
                    <div className="flex items-center gap-3">
                      <Progress value={clampPercentage(group.averageProgress)} className="flex-1" />
                      <span className="tabular text-xs font-bold text-primary">
                        {formatPercentage(group.averageProgress)}
                      </span>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>
        </>
      ) : null}
    </section>
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
